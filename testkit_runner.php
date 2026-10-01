<?php

/**
 * Replays the engine's testkit corpus through this driver: the language-neutral
 * JSON suites in frostlake/engine/src/test/resources/testkit/suites (format in the
 * SCHEMA.md beside them), every statement travelling Frostlake\Connection -> HTTP
 * -> DatabaseHttpServer. It ports the engine's Java reference runner (Compare,
 * HttpBackend, JsonSuiteTest), so a suite added on the engine side needs no change
 * here.
 *
 *   export FL_CORPUS=/path/to/frostlake/engine/src/test/resources/testkit
 *   FROSTLAKE_URL=frostlake://127.0.0.1:18082 php testkit_runner.php   # attach to a server
 *   FROSTLAKE_CLASSPATH="<engine classes + deps>" php testkit_runner.php  # or boot one
 *
 * FL_CORPUS names the testkit directory whose suites/*.json are replayed (without it
 * the run is skipped), FROSTLAKE_TESTKIT_REPORT the TSV report (default:
 * results/testkit-php.tsv, with missing-apis-php.md written beside it), and
 * FROSTLAKE_TESTKIT_FILTER keeps only the suites whose name contains its text.
 * test/run_tests.php runs it as one of its tests when FL_CORPUS is set.
 *
 * The contract, as SCHEMA.md lays it down:
 *   - a case whose skip clause names `php`, or `http` (the transport this driver
 *     rides), is reported SKIP;
 *   - every case runs on a connection, and so a session, of its own: the reset
 *     (ALTER SESSION SET MULTI_STATEMENT_COUNT = 0, then a fresh
 *     test_db.test_schema made current), then its steps in order, the first failed
 *     check ending it;
 *   - capabilities: SESSION, COLUMN_NAMES and UPDATE_COUNT. The protocol reports a
 *     refusal as a message only, so an expected error's code or sqlState is listed
 *     in missing-apis-php.md rather than checked;
 *   - the corpus records the text the wire carries, so every value the driver
 *     decoded is written back as that text, and both sides are then normalised as
 *     the reference does.
 *
 * Exits 1 when any case failed or errored, or FL_CORPUS holds no suites, else 0.
 */

declare(strict_types=1);

namespace Frostlake\Testkit;

use Frostlake\Connection;
use Frostlake\FrostlakeException;

require __DIR__ . '/src/Frostlake.php';

/** The names a skip clause can use for this runner. */
const BACKENDS = ['php', 'http'];

const RESET = [
    'ALTER SESSION SET MULTI_STATEMENT_COUNT = 0',
    'CREATE OR REPLACE DATABASE test_db',
    'USE DATABASE test_db',
    'CREATE OR REPLACE SCHEMA test_schema',
    'USE SCHEMA test_schema',
];

const SEMI_STRUCTURED = ['VARIANT', 'OBJECT', 'ARRAY'];

const MISSING_ERROR_CODE = 'ERROR_CODE: cannot check error code/sqlState (backend reports message only)';

const MAX_LISTED_FAILURES = 25;

/** One statement's outcome, in the shape of the reference runner's. */
final class ExecResult
{
    /**
     * @param ?list<string> $columns
     * @param ?list<list<?string>> $rows the first result grid as the wire's text; null when
     *        the statement produced no result set
     */
    public function __construct(
        public readonly ?array $columns = null,
        public readonly ?array $rows = null,
        public readonly int $updateCount = -1,
        public readonly ?string $error = null,
    ) {
    }
}

/** A failure of the transport or the harness rather than an expectation: the case is an ERROR. */
final class TransportFailure extends \RuntimeException
{
}

// -- running statements ---------------------------------------------------------

/**
 * Runs one statement and reads its first result set, as the reference does. A refusal
 * comes back in the outcome; only a transport failure throws.
 */
function run(Connection $conn, string $sql): ExecResult
{
    try {
        $results = $conn->executeAll($sql);
    } catch (FrostlakeException $e) {
        if (isTransportFailure($e->getMessage())) {
            throw new TransportFailure($e->getMessage(), 0, $e);
        }
        return new ExecResult(error: $e->getMessage());
    }
    if ($results === []) {
        return new ExecResult();
    }
    $first = $results[0];
    if ($first->columns === []) {
        // The driver answers a DML status grid ("number of rows inserted", ...) with its
        // affected-row count alone; the grid's own columns and cells are not observable.
        return new ExecResult([], [], $first->rowCount);
    }
    $names = [];
    $types = [];
    foreach ($first->columns as $column) {
        $names[] = (string) $column['name'];
        $types[] = baseType($column['dataType']);
    }
    $grid = [];
    // values, not rows: a self-join reports a column name twice, which rows cannot hold.
    foreach ($first->values as $row) {
        $cells = [];
        foreach ($row as $index => $value) {
            $type = $types[$index] ?? '';
            $text = wireText($value, $type);
            $cells[] = in_array($type, SEMI_STRUCTURED, true) ? semiStructuredValue($text) : $text;
        }
        $grid[] = $cells;
    }
    return new ExecResult($names, $grid, updateCountFromGrid($names, $grid));
}

/**
 * The driver raises one exception type for everything; these are the messages of the
 * failures that never reached an answer from the server, or never left the client.
 */
function isTransportFailure(string $message): bool
{
    foreach (['request failed:', 'unreadable response body', 'statement is not encodable', 'connection is closed'] as $prefix) {
        if (str_starts_with($message, $prefix)) {
            return true;
        }
    }
    return false;
}

/** 'NUMBER(38,0)' -> 'NUMBER'. */
function baseType(?string $dataType): string
{
    $name = strtoupper(trim((string) $dataType));
    $paren = strpos($name, '(');
    return $paren === false ? $name : rtrim(substr($name, 0, $paren));
}

/**
 * A decoded cell written back the way the engine sends it: the driver turns NUMBER and
 * BOOLEAN into PHP scalars, DATE and TIMESTAMP* into DateTimeImmutable and BINARY hex
 * into bytes, and hands everything else over as the wire's text.
 */
function wireText(mixed $value, string $type): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        // The shortest text that reads back as the same double, which is the wire's own
        // number for every value a double holds.
        return var_export($value, true);
    }
    if ($value instanceof \DateTimeInterface) {
        return temporalText($value, $type);
    }
    if (is_string($value)) {
        return $type === 'BINARY' || $type === 'VARBINARY' ? strtoupper(bin2hex($value)) : $value;
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return $json === false ? get_debug_type($value) : $json;
}

/**
 * The engine's temporal text: a DATE as 2024-01-31, a timestamp with three fractional
 * digits or as many more as the value needs, and TIMESTAMP_TZ/LTZ with a +HHMM offset.
 * PHP keeps microseconds, so digits past the sixth do not survive the driver.
 */
function temporalText(\DateTimeInterface $value, string $type): string
{
    if ($type === 'DATE') {
        return $value->format('Y-m-d');
    }
    $micros = (int) $value->format('u');
    $fraction = $micros % 1000 === 0 ? sprintf('%03d', intdiv($micros, 1000)) : sprintf('%06d', $micros);
    $text = $value->format('Y-m-d H:i:s') . '.' . $fraction;
    if ($type === 'TIMESTAMP_TZ' || $type === 'TIMESTAMP_LTZ') {
        $text .= ' ' . $value->format('O');
    }
    return $text;
}

/**
 * A VARIANT, OBJECT or ARRAY cell arrives as its JSON text, a string's quotes included,
 * while the suites record the value. As in the reference, one level is decoded: a cell
 * that is a JSON string becomes that string's contents, and anything else stays as it came.
 */
function semiStructuredValue(?string $cell): ?string
{
    if ($cell === null) {
        return null;
    }
    $parsed = json_decode($cell);
    return is_string($parsed) ? $parsed : $cell;
}

/**
 * The reference's derivation of a DML count from a result grid: a single row whose every
 * column is named "number of ..." counts its first cell.
 *
 * @param list<string> $columns
 * @param list<list<?string>> $rows
 */
function updateCountFromGrid(array $columns, array $rows): int
{
    if ($columns === [] || count($rows) !== 1) {
        return -1;
    }
    foreach ($columns as $name) {
        if (!str_starts_with(strtolower($name), 'number of')) {
            return -1;
        }
    }
    $first = trim((string) ($rows[0][0] ?? ''));
    return preg_match('/^[+-]?\d{1,18}$/D', $first) === 1 ? (int) $first : -1;
}

// -- comparing (Compare.java) -----------------------------------------------------

/**
 * The shared value normalisation, applied to both sides: NULL and the empty string
 * alike, booleans case-insensitively, anything numeric rounded to ten significant
 * digits, everything else the trimmed text.
 */
function norm(?string $raw): string
{
    if ($raw === null) {
        return 'NULL';
    }
    // Java's String.trim(): every character up to U+0020, at either end.
    $value = trim($raw, "\x00..\x20");
    if ($value === '' || strcasecmp($value, 'null') === 0) {
        return 'NULL';
    }
    if (strcasecmp($value, 'true') === 0) {
        return 'TRUE';
    }
    if (strcasecmp($value, 'false') === 0) {
        return 'FALSE';
    }
    return decimalText($value) ?? $value;
}

/**
 * new BigDecimal(text).round(new MathContext(10)).stripTrailingZeros().toPlainString(),
 * with any zero as "0"; null when the text is not a decimal number. Worked on the digit
 * string, since core PHP has no arbitrary-precision decimal.
 */
function decimalText(string $text): ?string
{
    if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $text, $match) !== 1) {
        return null;
    }
    $whole = $match[2];
    $fraction = $match[3] ?? '';
    $exponent = $match[4] ?? '';
    if ($whole === '' && $fraction === '') {
        return null;
    }
    // An exponent this far out would render as millions of zeros; no cell carries one.
    if (strlen(ltrim(ltrim($exponent, '+-'), '0')) > 6) {
        return null;
    }
    $digits = ltrim($whole . $fraction, '0');
    if ($digits === '') {
        return '0';
    }
    // The value is $digits x 10^-$scale.
    $scale = strlen($fraction) - (int) $exponent;
    if (strlen($digits) > 10) {
        $roundUp = $digits[10] >= '5';
        $scale -= strlen($digits) - 10;
        $digits = substr($digits, 0, 10);
        if ($roundUp) {
            $digits = incremented($digits);
        }
    }
    $trimmed = rtrim($digits, '0');
    $scale -= strlen($digits) - strlen($trimmed);
    $digits = $trimmed;
    if ($scale <= 0) {
        $plain = $digits . str_repeat('0', -$scale);
    } elseif (strlen($digits) > $scale) {
        $plain = substr($digits, 0, strlen($digits) - $scale) . '.' . substr($digits, -$scale);
    } else {
        $plain = '0.' . str_repeat('0', $scale - strlen($digits)) . $digits;
    }
    return ($match[1] === '-' ? '-' : '') . $plain;
}

/** A digit string plus one: '129' -> '130', '999' -> '1000'. */
function incremented(string $digits): string
{
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        if ($digits[$i] !== '9') {
            return substr($digits, 0, $i) . chr(ord($digits[$i]) + 1) . str_repeat('0', strlen($digits) - $i - 1);
        }
    }
    return '1' . str_repeat('0', strlen($digits));
}

/** An expected value from a suite as text, the way the reference reads it. */
function expectedText(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_float($value)) {
        return var_export($value, true);
    }
    if (is_int($value) || is_string($value)) {
        return (string) $value;
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
}

/** Java's equalsIgnoreCase: Unicode case folding through PCRE, where the text is UTF-8. */
function equalsIgnoringCase(string $expected, string $actual): bool
{
    $matched = @preg_match('/^' . preg_quote($expected, '/') . '$/iuD', $actual);
    return $matched === false ? strcasecmp($expected, $actual) === 0 : $matched === 1;
}

/** Java's haystack.toLowerCase().contains(needle.toLowerCase()), likewise. */
function containsIgnoringCase(string $haystack, string $needle): bool
{
    $matched = @preg_match('/' . preg_quote($needle, '/') . '/iu', $haystack);
    return $matched === false ? stripos($haystack, $needle) !== false : $matched === 1;
}

/**
 * Checks one step's expect block.
 *
 * @param ?array<string, mixed> $expect
 * @return array{0: bool, 1: string, 2: ?string} whether it held, why not, and a check
 *         this transport cannot express
 */
function check(?array $expect, ExecResult $result): array
{
    if (is_array($expect) && is_array($expect['error'] ?? null)) {
        return checkRefusal($expect['error'], $result);
    }
    if ($result->error !== null) {
        return [false, 'unexpected error: ' . $result->error, null];
    }
    if ($expect === null) {
        return [true, '', null];
    }
    if (array_key_exists('value', $expect)) {
        $want = expectedText($expect['value']);
        $actual = $result->rows[0][0] ?? null;
        if (norm($want) !== norm($actual)) {
            return [false, sprintf('value [%s] != expected [%s]', $actual ?? 'null', $want ?? 'null'), null];
        }
    }
    if (is_array($expect['rows'] ?? null)) {
        $diff = gridDiff($expect['rows'], $result->rows ?? [], ($expect['ordered'] ?? false) === true);
        if ($diff !== null) {
            return [false, $diff, null];
        }
    }
    if (array_key_exists('rowCount', $expect)) {
        $got = count($result->rows ?? []);
        if ($got !== (int) $expect['rowCount']) {
            return [false, sprintf('rowCount %d != expected %d', $got, (int) $expect['rowCount']), null];
        }
    }
    if (is_array($expect['columns'] ?? null)) {
        $mismatch = columnMismatch($expect['columns'], $result->columns ?? []);
        if ($mismatch !== null) {
            return [false, $mismatch, null];
        }
    }
    if (array_key_exists('updateCount', $expect)) {
        if ($result->updateCount !== (int) $expect['updateCount']) {
            return [false, sprintf('updateCount %d != expected %d', $result->updateCount, (int) $expect['updateCount']), null];
        }
    }
    return [true, '', null];
}

/**
 * The statement is expected to fail: it did, and with the named message.
 *
 * @param array<string, mixed> $error
 * @return array{0: bool, 1: string, 2: ?string}
 */
function checkRefusal(array $error, ExecResult $result): array
{
    if ($result->error === null) {
        return [false, 'expected an error, statement succeeded', null];
    }
    $want = $error['messageContains'] ?? null;
    if (is_string($want) && !containsIgnoringCase($result->error, $want)) {
        return [false, sprintf('error message [%s] does not contain [%s]', $result->error, $want), null];
    }
    if (($error['code'] ?? null) === null && ($error['sqlState'] ?? null) === null) {
        return [true, '', null];
    }
    return [true, '', MISSING_ERROR_CODE];
}

/**
 * @param list<mixed> $expected
 * @param list<string> $actual
 */
function columnMismatch(array $expected, array $actual): ?string
{
    if (count($expected) !== count($actual)) {
        return sprintf('column count %d != expected %d [%s]', count($actual), count($expected), implode(', ', $actual));
    }
    foreach (array_values($expected) as $i => $name) {
        $want = (string) expectedText($name);
        if (!equalsIgnoringCase($want, $actual[$i])) {
            return sprintf('column[%d] [%s] != expected [%s]', $i, $actual[$i], $want);
        }
    }
    return null;
}

/**
 * @param list<mixed> $want
 * @param list<list<?string>> $got
 */
function gridDiff(array $want, array $got, bool $ordered): ?string
{
    $expected = [];
    foreach ($want as $row) {
        $cells = [];
        foreach (is_array($row) ? $row : [$row] as $cell) {
            $cells[] = expectedText($cell);
        }
        $expected[] = canonicalRow($cells);
    }
    $actual = [];
    foreach ($got as $row) {
        $actual[] = canonicalRow($row);
    }
    if (!$ordered) {
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
    }
    if ($expected === $actual) {
        return null;
    }
    return sprintf('rows differ: expected [%s] got [%s]', implode(', ', $expected), implode(', ', $actual));
}

/** @param list<?string> $cells */
function canonicalRow(array $cells): string
{
    $parts = [];
    foreach ($cells as $cell) {
        $parts[] = norm($cell);
    }
    return implode('|', $parts);
}

// -- the corpus -------------------------------------------------------------------

/**
 * The backend a skip clause names for this runner, or null when it runs here.
 *
 * @param array<string, mixed> $test
 */
function skipHit(array $test): ?string
{
    $clause = is_array($test['skip'] ?? null) ? $test['skip'] : [];
    foreach (is_array($clause['backends'] ?? null) ? $clause['backends'] : [] as $backend) {
        if (is_string($backend) && in_array(strtolower($backend), BACKENDS, true)) {
            return $backend;
        }
    }
    return null;
}

/**
 * Runs one case on a connection of its own.
 *
 * @param array<string, mixed> $test
 * @param list<string> $missing checks this transport cannot express, appended to
 * @return array{0: string, 1: string, 2: string} status, failed step, detail
 */
function runCase(string $dsn, string $where, array $test, array &$missing): array
{
    $step = 0;
    try {
        $conn = \Frostlake\connect($dsn);
    } catch (FrostlakeException $e) {
        return ['ERROR', '', 'connect: ' . $e->getMessage()];
    }
    try {
        foreach (RESET as $sql) {
            $outcome = run($conn, $sql);
            if ($outcome->error !== null) {
                return ['ERROR', '', "reset failed [$sql]: {$outcome->error}"];
            }
        }
        foreach ($test['steps'] ?? [] as $step0 => $spec) {
            $step = $step0 + 1;
            $sql = (string) ($spec['sql'] ?? '');
            $expect = is_array($spec['expect'] ?? null) ? $spec['expect'] : null;
            [$ok, $why, $absent] = check($expect, run($conn, $sql));
            if ($absent !== null) {
                $missing[] = "$absent — $where step $step";
            }
            if (!$ok) {
                return ['FAIL', (string) $step, "$why  [sql: $sql]"];
            }
        }
        return ['PASS', '', ''];
    } catch (\Throwable $e) {
        return ['ERROR', $step > 0 ? (string) $step : '', get_debug_type($e) . ': ' . $e->getMessage()];
    } finally {
        $conn->close();
    }
}

/** A TSV cell: one line, no tabs. */
function cell(string $text): string
{
    return str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
}

/**
 * The engine to attach to: FROSTLAKE_URL, else one booted from FROSTLAKE_CLASSPATH with a
 * user.home of its own (an engine keeps stage files there) and stopped when the run ends.
 */
function engineDsn(): ?string
{
    $url = getenv('FROSTLAKE_URL');
    if (is_string($url) && $url !== '') {
        return $url;
    }
    $classpath = getenv('FROSTLAKE_CLASSPATH');
    if (!is_string($classpath) || $classpath === '') {
        return null;
    }
    $javaHome = getenv('JAVA_HOME');
    $java = is_string($javaHome) && $javaHome !== '' ? $javaHome . '/bin/java' : 'java';
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    if ($probe === false) {
        throw new \RuntimeException('cannot find a free port for the engine');
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $home = sys_get_temp_dir() . '/frostlake-testkit-php-' . getmypid();
    if (!is_dir($home) && !mkdir($home, 0700, true)) {
        throw new \RuntimeException("cannot create $home");
    }
    $server = proc_open(
        [$java, "-Duser.home=$home", '-cp', $classpath, 'dev.frostlake.http.DatabaseHttpServer', (string) $port],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $home
    );
    if ($server === false) {
        throw new \RuntimeException("cannot start $java");
    }
    register_shutdown_function(function () use ($server, $home): void {
        proc_terminate($server);
        proc_close($server);
        removeTree($home);
    });
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    for ($attempt = 0; $attempt < 150; $attempt++) {
        $body = @file_get_contents("http://127.0.0.1:$port/api/health", false, $context);
        if ($body !== false && str_contains($http_response_header[0] ?? '', ' 200')) {
            return "frostlake://127.0.0.1:$port";
        }
        if (!proc_get_status($server)['running']) {
            throw new \RuntimeException('the engine exited during startup');
        }
        usleep(200_000);
    }
    throw new \RuntimeException("the engine never became healthy on port $port");
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree("$path/$entry");
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

function main(): int
{
    // NTZ and DATE text carries no zone, and the driver reads it in the default one; the
    // engine's clock is UTC, so the replay reads it there too, whatever php.ini says.
    date_default_timezone_set('UTC');
    ini_set('serialize_precision', '-1');

    $corpus = getenv('FL_CORPUS');
    if (!is_string($corpus) || $corpus === '') {
        fwrite(STDOUT, "set FL_CORPUS to frostlake's engine/src/test/resources/testkit to replay the testkit corpus\n");
        return 0;
    }
    $env = getenv('FROSTLAKE_TESTKIT_REPORT');
    $report = is_string($env) && $env !== '' ? $env : __DIR__ . '/results/testkit-php.tsv';
    $env = getenv('FROSTLAKE_TESTKIT_FILTER');
    $filter = is_string($env) ? strtolower($env) : '';

    $files = glob(rtrim($corpus, '/') . '/suites/*.json') ?: [];
    if ($files === []) {
        fwrite(STDERR, "FL_CORPUS=$corpus holds no suites/*.json\n");
        return 1;
    }
    sort($files, SORT_STRING);
    $dsn = engineDsn();
    if ($dsn === null) {
        fwrite(STDERR, "no engine to run the corpus against (set FROSTLAKE_URL or FROSTLAKE_CLASSPATH)\n");
        return 1;
    }

    $counts = ['PASS' => 0, 'FAIL' => 0, 'ERROR' => 0, 'SKIP' => 0];
    $tsv = "suite\ttest\tstatus\tfailedStep\tdetail\tms\n";
    $missing = [];
    $failures = [];
    $replayed = 0;
    $started = hrtime(true);
    foreach ($files as $file) {
        $document = json_decode((string) file_get_contents($file), true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($document)) {
            fwrite(STDERR, "$file does not parse: " . json_last_error_msg() . "\n");
            return 1;
        }
        $suite = is_string($document['suite'] ?? null) ? $document['suite'] : basename($file, '.json');
        if ($filter !== '' && !str_contains(strtolower($suite), $filter)
            && !str_contains(strtolower(basename($file, '.json')), $filter)) {
            continue;
        }
        $replayed++;
        foreach ($document['tests'] ?? [] as $test) {
            $name = (string) ($test['name'] ?? '?');
            $hit = skipHit($test);
            if ($hit !== null) {
                $status = 'SKIP';
                $step = '';
                $detail = "skip[$hit]: " . (string) ($test['skip']['reason'] ?? 'skipped for this backend');
                $ms = 0;
            } else {
                $begin = hrtime(true);
                [$status, $step, $detail] = runCase($dsn, "$suite/$name", $test, $missing);
                $ms = intdiv(hrtime(true) - $begin, 1_000_000);
            }
            $counts[$status]++;
            $tsv .= implode("\t", [cell($suite), cell($name), $status, $step, cell($detail), (string) $ms]) . "\n";
            if (($status === 'FAIL' || $status === 'ERROR') && count($failures) < MAX_LISTED_FAILURES) {
                $failures[] = cell("  $suite / $name: $status" . ($step !== '' ? " at step $step" : '') . " $detail");
            }
        }
    }
    $seconds = (hrtime(true) - $started) / 1e9;

    $directory = dirname($report);
    if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
        fwrite(STDERR, "cannot create $directory\n");
        return 1;
    }
    $notes = "# Missing APIs for backend `php`\n\n"
        . "Checks the suites ask for that this transport cannot express. They are not failures:\n"
        . "the day the API exists they light up.\n\n";
    $notes .= $missing === [] ? "None.\n" : '- ' . implode("\n- ", $missing) . "\n";
    if (file_put_contents($report, $tsv) === false
        || file_put_contents($directory . '/missing-apis-php.md', $notes) === false) {
        fwrite(STDERR, "cannot write the report into $directory\n");
        return 1;
    }

    if ($failures !== []) {
        fwrite(STDOUT, "first failures:\n" . implode("\n", $failures) . "\n");
    }
    fwrite(STDOUT, sprintf(
        "%d suite(s) in %.1fs: PASS %d, FAIL %d, ERROR %d, SKIP %d; %d check(s) needing an API the transport lacks; report %s\n",
        $replayed,
        $seconds,
        $counts['PASS'],
        $counts['FAIL'],
        $counts['ERROR'],
        $counts['SKIP'],
        count($missing),
        $report
    ));
    // Errored cases count as failed here; the report tells the two apart.
    fwrite(STDOUT, sprintf(
        "testkit [php]: %d passed, %d failed, %d skipped\n",
        $counts['PASS'],
        $counts['FAIL'] + $counts['ERROR'],
        $counts['SKIP']
    ));
    return $counts['FAIL'] + $counts['ERROR'] > 0 ? 1 : 0;
}

exit(main());
