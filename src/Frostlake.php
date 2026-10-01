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
    /**
     * The code of the exception raised when the engine no longer holds the
     * connection's session (it expired, was released, or the server restarted)
     * and the statement was not re-run: the lost session held what a fresh one
     * cannot reproduce — an open transaction, or context set up with USE, SET,
     * ALTER SESSION or a temporary object. The statement did not run, and the
     * connection stays usable: its next statement starts a fresh session on the
     * DSN's database and schema.
     */
    public const SESSION_LOST = 1;
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
     * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, length: ?int, nullable: ?bool}> $columns
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
    /**
     * How long closing may spend releasing the session, in seconds, to connect
     * and to be answered.
     */
    private const RELEASE_TIMEOUT = 5.0;

    private string $baseUrl;
    private ?string $sessionId = null;
    /**
     * Whether the engine reports newSession, which arrived together with
     * requireSession and DELETE /api/sessions: null until the first answer
     * that names a session, which settles it either way.
     */
    private ?bool $tracksSessions = null;
    /**
     * What the session holds that a fresh one would not: context a statement
     * set up (USE, SET, ALTER SESSION, a temporary object, CREATE or DROP of a
     * database or schema), and an open transaction.
     */
    private bool $dirty = false;
    private bool $inTransaction = false;
    private bool $autoCommit = true;
    private bool $closed = false;
    /** @var list<string> */
    private array $pendingUse = [];
    /** @var list<string> the DSN's scope, put back on a fresh session */
    private array $sessionDefaults = [];

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
            $this->pendingUse[] = 'USE DATABASE ' . self::useIdent(rawurldecode($database));
        }
        $schema = $query['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $this->pendingUse[] = 'USE SCHEMA ' . self::useIdent($schema);
        }
        $this->sessionDefaults = $this->pendingUse;
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
    public function execute(string $sql, array $binds = [], ?int $multiStatementCount = null): Result
    {
        $results = $this->executeAll($sql, $binds, $multiStatementCount);
        return $results === [] ? new Result([], [], 0) : $results[0];
    }

    /**
     * Executes a statement string and returns every result set it produced, in
     * order. A single statement gives a one-element list.
     *
     * $multiStatementCount says how many statements this call carries, 0 for any
     * number; the engine refuses a call whose count differs, as the account does.
     * It rides on this one request and outranks the session's
     * MULTI_STATEMENT_COUNT for it without changing any session state, so there
     * is nothing to put back afterwards. Null sends nothing and leaves the
     * session's value deciding.
     *
     * @param list<mixed> $binds
     * @return list<Result>
     */
    public function executeAll(string $sql, array $binds = [], ?int $multiStatementCount = null): array
    {
        if ($this->closed) {
            throw new FrostlakeException('connection is closed');
        }
        if ($multiStatementCount !== null && $multiStatementCount < 0) {
            throw new FrostlakeException("multi-statement count cannot be negative, got $multiStatementCount");
        }
        $rendered = $binds === [] ? $sql : substitute($sql, $binds);
        return $this->shapeResults($this->perform($rendered, $multiStatementCount, $this->autoCommit));
    }

    /**
     * Opens a transaction: BEGIN, sent with autocommit off. The connection
     * leaves autocommit only once the engine has opened the transaction, so a
     * BEGIN that fails — refused, unreadable, never answered, lost with its
     * session, or never sent because a USE queued ahead of it was refused —
     * leaves every later statement committing as it runs.
     */
    public function beginTransaction(): void
    {
        if ($this->closed) {
            throw new FrostlakeException('connection is closed');
        }
        $this->perform('BEGIN', null, false);
        $this->autoCommit = false;
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

    /**
     * Runs the callable inside BEGIN ... COMMIT, rolling back on any exception
     * — a BEGIN that failed included, since one whose answer was lost may still
     * have opened a transaction on the engine.
     */
    public function transaction(callable $work): mixed
    {
        try {
            $this->beginTransaction();
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

    /**
     * Closes the connection and releases its session on the engine with one
     * DELETE /api/sessions/{id}, which also rolls back a transaction left open.
     * The release is best effort, bounded (RELEASE_TIMEOUT), and never throws;
     * an engine that predates it is sent nothing, and keeps the session until
     * its own idle expiry. Closing again sends nothing.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->releaseSession();
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

    /**
     * One statement on the session: the pending USE statements first, each one
     * of its own whatever the call declares and with the connection's own
     * autocommit, then the statement with $autoCommit, and the session's state
     * tracked from it.
     *
     * @return array<string, mixed>
     */
    private function perform(string $sql, ?int $multiStatementCount, bool $autoCommit): array
    {
        $this->applyPendingUse();
        $out = $this->run($sql, $multiStatementCount, $autoCommit);
        $this->trackSession($sql);
        return $out;
    }

    /**
     * Runs one statement on the session or — when the engine no longer holds
     * it — once more on a fresh one, if nothing the lost session held is lost
     * with it. Sent again, it carries the autocommit it was first sent with.
     *
     * @return array<string, mixed>
     */
    private function run(string $sql, ?int $multiStatementCount, bool $autoCommit): array
    {
        $out = $this->roundTrip($sql, $multiStatementCount, $autoCommit);
        if ($out !== null) {
            return $out;
        }
        $this->sessionLost();
        $this->applyPendingUse();
        $out = $this->roundTrip($sql, $multiStatementCount, $autoCommit);
        if ($out === null) {
            throw new FrostlakeException(
                'the engine refused a session it had just started',
                FrostlakeException::SESSION_LOST
            );
        }
        return $out;
    }

    /**
     * Sends the pending USE statements, one request each. A session lost or
     * replaced part-way takes the USEs already sent with it, so the whole of
     * the DSN's scope goes onto the fresh one: once, since an engine that loses
     * the session again within the same few requests is keeping none. And a
     * scope that fails part-way stays pending in full, so no statement runs in
     * a scope nobody chose.
     */
    private function applyPendingUse(): void
    {
        $restarted = false;
        while ($this->pendingUse !== []) {
            $queue = $this->pendingUse;
            try {
                $out = $this->roundTrip($queue[0]);
            } catch (FrostlakeException $e) {
                $this->pendingUse = $this->sessionDefaults;
                throw $e;
            }
            if ($out === null) {
                if ($restarted) {
                    throw new FrostlakeException(
                        'the engine refused a session it had just started',
                        FrostlakeException::SESSION_LOST
                    );
                }
                $restarted = true;
                $this->sessionLost();
            } elseif ($this->pendingUse === $queue || $restarted) {
                $this->pendingUse = array_slice($queue, 1);
            } else {
                // absorb() found the session replaced and queued the scope again.
                $restarted = true;
            }
        }
    }

    /**
     * The engine no longer holds the session: it expired, was released, or the
     * server restarted, and nothing ran. With a transaction or a moved context
     * gone with it, re-running would put the statement somewhere its author
     * did not intend, so that throws; either way the id is dropped and the
     * DSN's scope queued, so the next statement starts a fresh session there.
     */
    private function sessionLost(): void
    {
        $hadTransaction = $this->inTransaction;
        $hadContext = $this->dirty;
        $this->forgetSession();
        if ($hadTransaction) {
            throw new FrostlakeException(
                "the engine no longer holds this connection's session (it expired, was released, or the"
                . ' server restarted), so its open transaction is gone; the statement did not run',
                FrostlakeException::SESSION_LOST
            );
        }
        if ($hadContext) {
            throw new FrostlakeException(
                "the engine no longer holds this connection's session (it expired, was released, or the"
                . ' server restarted), and the context set up on it (USE, SET, ALTER SESSION or a temporary'
                . ' object) went with it, so the statement was not re-run; the next statement starts a'
                . " fresh session on the connection's scope",
                FrostlakeException::SESSION_LOST
            );
        }
    }

    /**
     * Starts over: no session, nothing held on one, and the DSN's scope queued
     * for the next statement. A transaction lost with the session takes the
     * client's autocommit-off with it.
     */
    private function forgetSession(): void
    {
        if ($this->inTransaction) {
            $this->autoCommit = true;
        }
        $this->sessionId = null;
        $this->dirty = false;
        $this->inTransaction = false;
        $this->pendingUse = $this->sessionDefaults;
    }

    /**
     * Keeps the connection's picture of its session in step with a statement
     * that succeeded: whether it left context behind that a fresh session would
     * not have, and whether a transaction is open.
     */
    private function trackSession(string $sql): void
    {
        foreach (splitStatements($sql) as $statement) {
            if (touchesSession($statement)) {
                $this->dirty = true;
            }
            $effect = transactionEffect($statement);
            if ($effect === 'begin') {
                $this->inTransaction = true;
            } elseif ($effect === 'end') {
                $this->inTransaction = false;
            }
        }
    }

    /**
     * Learns from an answer which session it ran in, and whether the engine
     * tracks sessions: an answer naming one carries newSession, or comes from
     * an engine older than the field, requireSession and DELETE /api/sessions.
     *
     * @param array<string, mixed> $out
     */
    private function absorb(array $out, ?string $sentId): void
    {
        $id = $out['sessionId'] ?? null;
        if (!is_string($id)) {
            return;
        }
        if (array_key_exists('newSession', $out)) {
            $this->tracksSessions = true;
            // The engine ran the statement in a fresh session in place of ours,
            // so whatever the old one held is gone, and the DSN's scope goes
            // back on before the next statement.
            if ($out['newSession'] === true && $sentId !== null) {
                $this->forgetSession();
            }
        } elseif ($this->tracksSessions === null) {
            $this->tracksSessions = false;
        }
        $this->sessionId = $id;
    }

    /**
     * One DELETE /api/sessions/{id}, bounded and never throwing. Releasing the
     * session also rolls back a transaction it left open.
     */
    private function releaseSession(): void
    {
        $id = $this->sessionId;
        $this->sessionId = null;
        $this->inTransaction = false;
        if ($id === null || $this->tracksSessions !== true) {
            return;
        }
        $context = stream_context_create(['http' => [
            'method' => 'DELETE',
            'ignore_errors' => true,
            'timeout' => self::RELEASE_TIMEOUT,
        ]]);
        try {
            // Best effort: whatever this does not release, the engine's idle expiry does.
            @file_get_contents($this->baseUrl . '/api/sessions/' . rawurlencode($id), false, $context);
        } catch (\Throwable) {
        }
    }

    /**
     * One POST /api/execute: the decoded answer, or null when the engine
     * refused the session id as one it no longer holds. Nothing ran then.
     * $autoCommit is the request's autocommit, the connection's own when null.
     *
     * @return array<string, mixed>|null
     */
    private function roundTrip(string $sql, ?int $multiStatementCount = null, ?bool $autoCommit = null): ?array
    {
        $sentId = $this->sessionId;
        // Resume this session or refuse, rather than have the engine start a
        // fresh one under the same id where the statement would run without
        // the context set up earlier. Only to an engine known to take the
        // field: an older one might refuse a field it does not know.
        $required = $sentId !== null && $this->tracksSessions === true;
        $payload = ['sql' => $sql, 'autoCommit' => $autoCommit ?? $this->autoCommit];
        if ($sentId !== null) {
            $payload['sessionId'] = $sentId;
        }
        if ($required) {
            $payload['requireSession'] = true;
        }
        // Absent unless this call asked for a count: a request without the field
        // is the one the server has always been sent, and the session decides.
        if ($multiStatementCount !== null) {
            $payload['multiStatementCount'] = $multiStatementCount;
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
        // A 404 that names no session is the refusal requireSession asked for.
        if (
            $required
            && preg_match('#^HTTP/\S+\s+404\b#', $http_response_header[0] ?? '') === 1
            && ($out['success'] ?? false) !== true
            && ($out['sessionId'] ?? null) === null
        ) {
            return null;
        }
        $this->absorb($out, $sentId);
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
                'length' => isset($column['length']) ? (int) $column['length'] : null,
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

    /**
     * A DSN's database or schema, rendered for USE. A name that is a valid
     * unquoted identifier means what it means unquoted in SQL — the upper-case
     * object it folds to — so it is folded before it is quoted; a name already
     * written in double quotes is used as it stands, for an exact-case match;
     * anything else is quoted exactly as given. Quoted as given, a lower-case
     * name would ask for a lower-case object, which USE refuses: it resolves
     * names exactly, as the account does.
     */
    private static function useIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name) === 1) {
            // Not strtoupper: before PHP 8.2 it follows the locale, which can
            // turn an i into something no identifier holds.
            return self::quoteIdent(strtr($name, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        }
        $inner = substr($name, 1, -1);
        if (strlen($name) >= 2 && $name[0] === '"' && $name[strlen($name) - 1] === '"'
            && !str_contains(str_replace('""', '', $inner), '"')) {
            return $name;
        }
        return self::quoteIdent($name);
    }
}

/**
 * Whether a result set is a DML status row rather than data. The protocol
 * carries no statement type, so this goes by shape: DML answers with a single
 * row whose every column is a "number of ..." counter. INSERT and DELETE report
 * one, UPDATE adds "number of multi-joined rows updated", and MERGE reports both
 * an inserted and an updated count.
 *
 * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, length: ?int, nullable: ?bool}> $columns
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
 * @param list<array{name: string, dataType: ?string, precision: ?int, scale: ?int, length: ?int, nullable: ?bool}> $columns
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

// -- session tracking ---------------------------------------------------------

/**
 * The words that may sit between CREATE, DROP or ALTER and the kind of object
 * the statement names.
 */
const OBJECT_MODIFIERS = [
    'OR', 'REPLACE', 'TRANSIENT', 'TEMPORARY', 'TEMP', 'VOLATILE', 'LOCAL', 'GLOBAL', 'SECURE', 'IF',
    'NOT', 'EXISTS', 'PUBLIC', 'PRIVATE', 'ICEBERG', 'DYNAMIC', 'HYBRID', 'EVENT', 'RECURSIVE',
    'MATERIALIZED', 'EXTERNAL',
];

/** The modifiers that make an object last only as long as its session. */
const TEMPORARY_MODIFIERS = ['TEMPORARY', 'TEMP', 'VOLATILE'];

/**
 * The request split on its top-level semicolons, blank pieces dropped. A
 * semicolon inside a literal, a quoted identifier, a $$ body or a comment does
 * not split: the same constructs substitute() steps over. A scripting block is
 * split along with everything else, which only makes the checks below more
 * willing to flag a request, the safe direction to be wrong in.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $pieces = [];
    $start = 0;
    $length = strlen($sql);
    $i = 0;
    while ($i < $length) {
        $past = skipNonCode($sql, $i);
        if ($past !== null) {
            $i = $past;
        } elseif ($sql[$i] === ';') {
            $pieces[] = substr($sql, $start, $i - $start);
            $start = $i + 1;
            $i++;
        } else {
            $i++;
        }
    }
    $pieces[] = substr($sql, $start);
    $statements = [];
    foreach ($pieces as $piece) {
        if (trim($piece) !== '') {
            $statements[] = $piece;
        }
    }
    return $statements;
}

/**
 * Index just past the literal, quoted identifier, $$ body or comment starting
 * at $i, or null when $i is code; the rules substitute() follows.
 */
function skipNonCode(string $sql, int $i): ?int
{
    $ch = $sql[$i];
    $next = $sql[$i + 1] ?? '';
    if ($ch === "'") {
        return skipString($sql, $i);
    }
    if ($ch === '"') {
        return skipQuoted($sql, $i);
    }
    if (($ch === '-' && $next === '-') || ($ch === '/' && $next === '/')) {
        return skipLine($sql, $i);
    }
    if ($ch === '/' && $next === '*') {
        $stop = strpos($sql, '*/', $i + 2);
        return $stop === false ? strlen($sql) : $stop + 2;
    }
    if ($ch === '$' && $next === '$') {
        return skipDollarQuoted($sql, $i);
    }
    return null;
}

/**
 * A byte of an unquoted identifier or keyword: $ is one, which is why A$$B is
 * a name, and so is every byte of a multibyte letter.
 */
function isWordByte(string $ch): bool
{
    return $ch === '_' || $ch === '$' || ctype_alnum($ch) || ord($ch) >= 0x80;
}

/**
 * Up to $limit leading words of a statement, upper-cased, skipping whitespace
 * and comments and stopping at the first thing that is not a word.
 *
 * @return list<string>
 */
function leadingWords(string $statement, int $limit): array
{
    $words = [];
    $length = strlen($statement);
    $i = 0;
    while (count($words) < $limit && $i < $length) {
        $ch = $statement[$i];
        $next = $statement[$i + 1] ?? '';
        if (ctype_space($ch)) {
            $i++;
        } elseif (($ch === '-' && $next === '-') || ($ch === '/' && $next === '/')) {
            $i = skipLine($statement, $i);
        } elseif ($ch === '/' && $next === '*') {
            $stop = strpos($statement, '*/', $i + 2);
            $i = $stop === false ? $length : $stop + 2;
        } elseif (isWordByte($ch)) {
            $start = $i;
            while ($i < $length && isWordByte($statement[$i])) {
                $i++;
            }
            $words[] = strtoupper(substr($statement, $start, $i - $start));
        } else {
            break;
        }
    }
    return $words;
}

/**
 * Whether a statement leaves behind state a fresh session would not have: a
 * moved scope (USE, or CREATE or DROP of a DATABASE or SCHEMA), a session
 * variable or setting (SET, UNSET, ALTER SESSION), or a temporary object.
 * CREATE TABLE and its kind leave the session as it was.
 */
function touchesSession(string $statement): bool
{
    $words = leadingWords($statement, 16);
    $verb = array_shift($words);
    if ($verb === 'USE' || $verb === 'SET' || $verb === 'UNSET') {
        return true;
    }
    if ($verb !== 'ALTER' && $verb !== 'CREATE' && $verb !== 'DROP') {
        return false;
    }
    $modifiers = 0;
    $temporary = false;
    while ($modifiers < count($words) && in_array($words[$modifiers], OBJECT_MODIFIERS, true)) {
        $temporary = $temporary || in_array($words[$modifiers], TEMPORARY_MODIFIERS, true);
        $modifiers++;
    }
    $kind = $words[$modifiers] ?? null;
    if ($verb === 'ALTER') {
        return $kind === 'SESSION';
    }
    return $kind === 'DATABASE' || $kind === 'SCHEMA' || ($verb === 'CREATE' && $temporary);
}

/**
 * What a statement does to the session's transaction: 'begin', 'end', or null
 * for nothing. BEGIN on its own (or with TRANSACTION, WORK or NAME) opens one;
 * BEGIN followed by a statement opens a scripting block instead.
 */
function transactionEffect(string $statement): ?string
{
    $words = leadingWords($statement, 2);
    $first = $words[0] ?? null;
    if ($first === 'COMMIT' || $first === 'ROLLBACK') {
        return 'end';
    }
    if ($words === ['START', 'TRANSACTION']) {
        return 'begin';
    }
    if ($first !== 'BEGIN') {
        return null;
    }
    return count($words) === 1 || in_array($words[1], ['TRANSACTION', 'WORK', 'NAME'], true) ? 'begin' : null;
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
