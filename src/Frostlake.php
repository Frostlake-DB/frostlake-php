<?php

/**
 * A zero-dependency PHP driver for Frostlake, speaking the engine's HTTP
 * protocol against a running DatabaseHttpServer.
 *
 *   require "src/Frostlake.php";
 *   $conn = Frostlake\connect("frostlake://localhost:18082/MY_DB?schema=PUBLIC");
 *   $result = $conn->execute("SELECT id, name FROM people WHERE id = ?", [1]);
 *   $result->rows; // [["ID" => 1, "NAME" => "Ada"]]
 *
 * Parameters are inlined client-side (the protocol has no server-side binding),
 * with the same rules as Frostlake's other drivers. Rows are associative arrays
 * keyed by column name; NUMBER/BOOLEAN cells arrive as PHP numerics/booleans
 * (integers beyond PHP_INT_MAX stay exact digit strings), DATE/TIMESTAMP* as
 * DateTimeImmutable, BINARY as raw bytes. A bound date-time is sent as
 * TIMESTAMP_TZ, since a PHP one always carries a UTC offset.
 *
 * A multi-statement execute() exposes the first result set; executeAll() returns
 * all of them.
 */

declare(strict_types=1);

namespace Frostlake;

const VERSION = '0.1.0';

final class FrostlakeException extends \RuntimeException
{
}

/** Wraps raw bytes so they bind as X'hex' instead of a string literal. */
final class Binary
{
    public function __construct(public readonly string $bytes)
    {
    }
}

final class Result
{
    /**
     * $values is every cell, positionally aligned with $columns — the shape the
     * wire actually delivered. $rows keys those cells by column name, which a
     * self-join cannot represent: SELECT a.id, b.id reports ID twice and the
     * later one wins, so $values stays the lossless view.
     *
     * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, nullable: ?bool}> $columns
     * @param list<array<string, mixed>> $rows
     * @param list<list<mixed>> $values
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $rowCount,
        public readonly array $values = [],
    ) {
    }
}

/** Connects and verifies the server is reachable via GET /api/health. */
function connect(string $dsn): Connection
{
    $conn = new Connection($dsn);
    $conn->ping();
    return $conn;
}

final class Connection
{
    private string $baseUrl;
    private ?string $sessionId = null;
    private bool $autoCommit = true;
    private bool $closed = false;
    /** @var list<string> */
    private array $pendingUse = [];

    public function __construct(string $dsn)
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new FrostlakeException("invalid DSN: $dsn");
        }
        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'frostlake' && $scheme !== 'http') {
            throw new FrostlakeException('DSN must start with frostlake:// or http://');
        }
        $port = $parts['port'] ?? 18082;
        $this->baseUrl = "http://{$parts['host']}:$port";
        $database = trim($parts['path'] ?? '', '/');
        parse_str($parts['query'] ?? '', $query);
        if ($database !== '') {
            $this->pendingUse[] = 'USE DATABASE ' . self::quoteIdent(rawurldecode($database));
        }
        $schema = $query['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $this->pendingUse[] = 'USE SCHEMA ' . self::quoteIdent($schema);
        }
    }

    public function ping(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents($this->baseUrl . '/api/health', false, $context);
        $status = $http_response_header[0] ?? '';
        if ($body === false || !str_contains($status, ' 200')) {
            throw new FrostlakeException("cannot reach {$this->baseUrl}" . ($status !== '' ? " ($status)" : ''));
        }
    }

    /**
     * Executes one statement and returns its first result set; rows are
     * associative arrays keyed by column name and rowCount is the affected-row
     * count for DML.
     *
     * @param list<mixed> $binds
     */
    public function execute(string $sql, array $binds = []): Result
    {
        $results = $this->executeAll($sql, $binds);
        return $results === [] ? new Result([], [], 0) : $results[0];
    }

    /**
     * Executes a statement string and returns every result set it produced, in
     * order. A single statement gives a one-element list.
     *
     * @param list<mixed> $binds
     * @return list<Result>
     */
    public function executeAll(string $sql, array $binds = []): array
    {
        if ($this->closed) {
            throw new FrostlakeException('connection is closed');
        }
        while ($this->pendingUse !== []) {
            $this->roundTrip(array_shift($this->pendingUse));
        }
        $rendered = $binds === [] ? $sql : substitute($sql, $binds);
        return $this->shapeResults($this->roundTrip($rendered));
    }

    public function beginTransaction(): void
    {
        // Drain the DSN's USE statements first: they belong to the session, not
        // to the transaction about to open.
        while ($this->pendingUse !== []) {
            $this->roundTrip(array_shift($this->pendingUse));
        }
        $this->autoCommit = false;
        $this->execute('BEGIN');
    }

    public function commit(): void
    {
        // finally, not a trailing assignment: a COMMIT that fails still ends the
        // client's transaction, and leaving the flag false would silently run
        // every later statement inside a transaction nobody closes.
        try {
            $this->execute('COMMIT');
        } finally {
            $this->autoCommit = true;
        }
    }

    public function rollback(): void
    {
        try {
            $this->execute('ROLLBACK');
        } finally {
            $this->autoCommit = true;
        }
    }

    /** Runs the callable inside BEGIN ... COMMIT, rolling back on any exception. */
    public function transaction(callable $work): mixed
    {
        $this->beginTransaction();
        try {
            $result = $work($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollback();
            } catch (\Throwable) {
                // A failed rollback must not replace the exception that caused it.
            }
            throw $e;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** False between beginTransaction() and the commit or rollback that ends it. */
    public function isAutoCommit(): bool
    {
        return $this->autoCommit;
    }

    /** @return array<string, mixed> */
    private function roundTrip(string $sql): array
    {
        $payload = ['sql' => $sql, 'autoCommit' => $this->autoCommit];
        if ($this->sessionId !== null) {
            $payload['sessionId'] = $this->sessionId;
        }
        $content = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($content === false) {
            throw new FrostlakeException('statement is not encodable as JSON: ' . json_last_error_msg());
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $content,
            'ignore_errors' => true, // failed statements answer non-2xx WITH the error payload in the body
            'timeout' => 300,
        ]]);
        $body = @file_get_contents($this->baseUrl . '/api/execute', false, $context);
        if ($body === false) {
            throw new FrostlakeException("request failed: cannot reach {$this->baseUrl}");
        }
        $out = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($out)) {
            $status = $http_response_header[0] ?? 'no status';
            throw new FrostlakeException("unreadable response body ($status)");
        }
        if (is_string($out['sessionId'] ?? null)) {
            $this->sessionId = $out['sessionId'];
        }
        if (($out['success'] ?? false) !== true) {
            throw new FrostlakeException(
                is_string($out['errorMessage'] ?? null) ? $out['errorMessage'] : 'statement failed'
            );
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $out
     * @return list<Result>
     */
    private function shapeResults(array $out): array
    {
        $results = [];
        foreach ($out['resultSets'] ?? [] as $resultSet) {
            if (is_array($resultSet)) {
                $results[] = $this->shapeResult($resultSet);
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $resultSet */
    private function shapeResult(array $resultSet): Result
    {
        $columns = [];
        foreach ($resultSet['columns'] ?? [] as $column) {
            $columns[] = [
                'name' => $column['name'],
                'dataType' => $column['dataType'] ?? null,
                'precision' => isset($column['precision']) ? (int) $column['precision'] : null,
                'scale' => isset($column['scale']) ? (int) $column['scale'] : null,
                'nullable' => isset($column['nullable']) ? (bool) $column['nullable'] : null,
            ];
        }
        $values = [];
        $rows = [];
        foreach ($resultSet['rows'] ?? [] as $raw) {
            $cells = [];
            $row = [];
            foreach ($columns as $i => $column) {
                $cell = convertValue($raw[$i] ?? null, $column['dataType']);
                $cells[] = $cell;
                $row[$column['name']] = $cell;
            }
            $values[] = $cells;
            $rows[] = $row;
        }
        if (count($values) === 1 && isDmlStatus($columns)) {
            return new Result([], [], dmlRowCount($columns, $values[0]));
        }
        return new Result($columns, $rows, count($rows), $values);
    }

    /**
     * Always quoted. Leaving "unambiguous" names bare let through ones that
     * cannot legally appear unquoted — 1ABC starts with a digit, SELECT is
     * reserved — and quoting costs nothing: "NAME" and NAME name the same
     * object, so only genuinely lower-case names are affected and those had to
     * be quoted anyway.
     */
    private static function quoteIdent(string $name): string
    {
        if ($name === '') {
            throw new FrostlakeException('identifier cannot be empty');
        }
        return '"' . str_replace('"', '""', $name) . '"';
    }
}

/**
 * Whether a result set is a DML status row rather than data. The protocol
 * carries no statement type, so this goes by shape: DML answers with a single
 * row whose every column is a "number of ..." counter. INSERT and DELETE report
 * one, UPDATE adds "number of multi-joined rows updated", and MERGE reports both
 * an inserted and an updated count.
 *
 * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, nullable: ?bool}> $columns
 */
function isDmlStatus(array $columns): bool
{
    if ($columns === []) {
        return false;
    }
    foreach ($columns as $column) {
        if (!str_starts_with(strtolower((string) $column['name']), 'number of ')) {
            return false;
        }
    }
    return true;
}

/**
 * Total rows affected. "number of multi-joined rows updated" is a diagnostic
 * sub-count of rows already counted as updated, so only the "number of rows ..."
 * counters are summed.
 *
 * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, nullable: ?bool}> $columns
 * @param list<mixed> $cells
 */
function dmlRowCount(array $columns, array $cells): int
{
    $total = 0;
    foreach ($columns as $i => $column) {
        if (!str_starts_with(strtolower((string) $column['name']), 'number of rows ')) {
            continue;
        }
        $value = $cells[$i] ?? null;
        if ($value !== null) {
            $total += (int) $value;
        }
    }
    return $total;
}

function convertValue(mixed $value, ?string $dataType): mixed
{
    if ($value === null) {
        return null;
    }
    switch (strtoupper($dataType ?? '')) {
        case 'DATE':
            return is_string($value) ? parseTemporal($value) : $value;
        case 'TIMESTAMP':
        case 'TIMESTAMP_NTZ':
        case 'TIMESTAMP_LTZ':
        case 'TIMESTAMP_TZ':
        case 'DATETIME':
            return is_string($value) ? parseTemporal($value) : $value;
        case 'BINARY':
        case 'VARBINARY':
            if (is_string($value) && strlen($value) % 2 === 0 && ctype_xdigit($value)) {
                return hex2bin($value);
            }
            return $value;
        default:
            return $value;
    }
}

function parseTemporal(string $text): \DateTimeImmutable|string
{
    try {
        return new \DateTimeImmutable(trimFraction($text, 6));
    } catch (\Exception) {
        return $text;
    }
}

/** The engine emits up to nanosecond fractions; PHP parses at most 6 digits. */
function trimFraction(string $text, int $maxDigits): string
{
    $dot = strpos($text, '.');
    if ($dot === false) {
        return $text;
    }
    $end = $dot + 1;
    while ($end < strlen($text) && ctype_digit($text[$end])) {
        $end++;
    }
    $keep = min($end - $dot - 1, $maxDigits);
    return substr($text, 0, $dot + 1 + $keep) . substr($text, $end);
}

// -- client-side parameter binding -------------------------------------------

/** @param list<mixed> $binds */
function substitute(string $sql, array $binds): string
{
    $out = '';
    $next = 0;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        if ($ch === "'") {
            $j = skipString($sql, $i);
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '"') {
            $j = skipQuoted($sql, $i);
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            $j = skipLine($sql, $i);
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
            $stop = strpos($sql, '*/', $i + 2);
            $j = $stop === false ? $length : $stop + 2;
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '/' && ($sql[$i + 1] ?? '') === '/') {
            $j = skipLine($sql, $i);
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '$' && ($sql[$i + 1] ?? '') === '$') {
            $j = skipDollarQuoted($sql, $i);
            $out .= substr($sql, $i, $j - $i);
            $i = $j - 1;
        } elseif ($ch === '?') {
            if ($next >= count($binds)) {
                throw new FrostlakeException('not enough bind values for placeholders');
            }
            $out .= formatLiteral($binds[$next++]);
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

function skipString(string $sql, int $i): int
{
    $j = $i + 1;
    $length = strlen($sql);
    while ($j < $length) {
        if ($sql[$j] === '\\') {
            $j += 2; // backslash always escapes
        } elseif ($sql[$j] === "'") {
            if (($sql[$j + 1] ?? '') === "'") {
                $j += 2;
            } else {
                return $j + 1;
            }
        } else {
            $j++;
        }
    }
    return $j;
}

function skipQuoted(string $sql, int $i): int
{
    $j = $i + 1;
    $length = strlen($sql);
    while ($j < $length) {
        if ($sql[$j] === '"') {
            if (($sql[$j + 1] ?? '') === '"') {
                $j += 2;
                continue;
            }
            return $j + 1;
        }
        $j++;
    }
    return $j;
}

/**
 * Steps over a $$...$$ block. UDF and procedure bodies are written that way, so
 * a ? inside one is part of the body, not a placeholder.
 */
function skipDollarQuoted(string $sql, int $i): int
{
    $stop = strpos($sql, '$$', $i + 2);
    return $stop === false ? strlen($sql) : $stop + 2;
}

function skipLine(string $sql, int $i): int
{
    $j = strpos($sql, "\n", $i);
    return $j === false ? strlen($sql) : $j + 1;
}

function encodeString(string $text): string
{
    return "'" . str_replace("'", "''", str_replace('\\', '\\\\', $text)) . "'";
}

function formatLiteral(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            throw new FrostlakeException("non-finite number $value");
        }
        // Not (string) $value: that formats with the precision ini setting, so
        // 0.1 + 0.2 would reach the engine as 0.3 and 1.0 as the integer 1.
        // var_export writes the shortest text that reads back as the same float.
        return var_export($value, true);
    }
    if (is_string($value)) {
        return encodeString($value);
    }
    if ($value instanceof Binary) {
        return "X'" . strtoupper(bin2hex($value->bytes)) . "'";
    }
    if ($value instanceof \DateTimeInterface) {
        // A PHP date-time always carries a UTC offset, so it maps to
        // TIMESTAMP_TZ; casting to NTZ here silently discarded that offset.
        return "'" . $value->format('Y-m-d\TH:i:s.uP') . "'::TIMESTAMP_TZ";
    }
    if (is_array($value)) {
        if (!array_is_list($value)) {
            throw new FrostlakeException('associative arrays are not bindable');
        }
        $parts = [];
        foreach ($value as $element) {
            $parts[] = formatLiteral($element);
        }
        return '[' . implode(', ', $parts) . ']';
    }
    $type = get_debug_type($value);
    throw new FrostlakeException("unsupported bind type $type");
}
