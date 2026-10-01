<?php

/**
 * Self-contained test runner (no PHPUnit needed). Boots a real
 * DatabaseHttpServer from FROSTLAKE_CLASSPATH; when the variable is unset the
 * integration tests are skipped and only the substitution unit tests run.
 *
 *   php test/run_tests.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/Frostlake.php';

use Frostlake\Binary;
use Frostlake\FrostlakeException;

$passed = 0;
$failed = 0;
$skipped = 0;

final class SkippedTest extends \RuntimeException
{
}

function runTest(string $name, callable $test): void
{
    global $passed, $failed, $skipped;
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "ok   $name\n");
    } catch (SkippedTest $e) {
        $skipped++;
        fwrite(STDOUT, "skip $name: {$e->getMessage()}\n");
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDOUT, "FAIL $name: {$e->getMessage()}\n");
    }
}

function assertSame(mixed $expected, mixed $actual, string $what = 'value'): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \RuntimeException("$what: expected $e, got $a");
    }
}

function assertContains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException("expected \"$haystack\" to contain \"$needle\"");
    }
}

// -- server boot --------------------------------------------------------------

$dsn = null;
$server = null;
$classpath = getenv('FROSTLAKE_CLASSPATH');
if (is_string($classpath) && $classpath !== '') {
    $javaHome = getenv('JAVA_HOME');
    $java = is_string($javaHome) && $javaHome !== '' ? $javaHome . '/bin/java' : 'java';
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);
    $server = proc_open(
        [$java, '-cp', $classpath, 'dev.frostlake.http.DatabaseHttpServer', (string) $port],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    register_shutdown_function(function () use ($server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    });
    $healthy = false;
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $body = @file_get_contents("http://127.0.0.1:$port/api/health", false, $context);
        if ($body !== false && str_contains($http_response_header[0] ?? '', ' 200')) {
            $healthy = true;
            break;
        }
        usleep(200_000);
    }
    if (!$healthy) {
        fwrite(STDERR, "Frostlake server did not become healthy\n");
        exit(1);
    }
    $dsn = "frostlake://127.0.0.1:$port";
}

function openOrSkip(string $database): \Frostlake\Connection
{
    global $dsn;
    if ($dsn === null) {
        throw new SkippedTest('FROSTLAKE_CLASSPATH not set');
    }
    $conn = Frostlake\connect($dsn);
    $conn->execute("CREATE OR REPLACE DATABASE $database");
    $conn->execute("USE DATABASE $database");
    return $conn;
}

// -- unit tests ---------------------------------------------------------------

runTest('substitution skips literals, identifiers and comments', function () {
    $rendered = Frostlake\substitute("SELECT 'a?b', \"c?d\", ? -- e?f\n, ? /* g?h */", ['x', 2]);
    assertSame("SELECT 'a?b', \"c?d\", 'x' -- e?f\n, 2 /* g?h */", $rendered);
});

runTest('string encoding doubles backslashes then quotes', function () {
    assertSame("SELECT 'Ada O''Hara \\\\ Byron'", Frostlake\substitute('SELECT ?', ["Ada O'Hara \\ Byron"]));
});

runTest('typed literal formatting', function () {
    assertSame('NULL', Frostlake\formatLiteral(null));
    assertSame('TRUE', Frostlake\formatLiteral(true));
    assertSame('FALSE', Frostlake\formatLiteral(false));
    assertSame('42', Frostlake\formatLiteral(42));
    assertSame('9.5', Frostlake\formatLiteral(9.5));
    assertSame("X'CAFE'", Frostlake\formatLiteral(new Binary("\xCA\xFE")));
    assertSame("[1, 'a']", Frostlake\formatLiteral([1, 'a']));
    assertSame('[[1, 2], NULL]', Frostlake\formatLiteral([[1, 2], null]));
});

runTest('floats keep every digit that reads back as the same float', function () {
    // (string) $float would format with the precision ini setting: 0.3 here,
    // and the integer 1 for 1.0.
    assertSame('0.30000000000000004', Frostlake\formatLiteral(0.1 + 0.2));
    assertSame('1.0', Frostlake\formatLiteral(1.0));
    assertSame('1.0E+20', Frostlake\formatLiteral(1e20));
});

runTest('date-times bind as TIMESTAMP_TZ carrying their offset', function () {
    $moment = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('+02:00'));
    assertSame("'2026-01-02T03:04:05.000000+02:00'::TIMESTAMP_TZ", Frostlake\formatLiteral($moment));
    $utc = new \DateTimeImmutable('2026-01-02 03:04:05.5', new \DateTimeZone('UTC'));
    assertSame("'2026-01-02T03:04:05.500000+00:00'::TIMESTAMP_TZ", Frostlake\formatLiteral($utc));
});

runTest('substitution steps over $$ bodies', function () {
    // A ? inside a UDF or procedure body is part of the body, not a placeholder.
    assertSame(
        "SELECT \$\$a ? b\$\$, 1",
        Frostlake\substitute('SELECT $$a ? b$$, ?', [1])
    );
});

runTest('bind errors are refused with a message', function () {
    $cases = [
        ['not enough bind values', function () { Frostlake\substitute('SELECT ?, ?', [1]); }],
        ['associative arrays are not bindable', function () { Frostlake\formatLiteral(['a' => 1]); }],
        ['unsupported bind type', function () { Frostlake\formatLiteral(new \stdClass()); }],
        ['non-finite number', function () { Frostlake\formatLiteral(INF); }],
    ];
    foreach ($cases as [$needle, $trigger]) {
        $message = null;
        try {
            $trigger();
        } catch (FrostlakeException $e) {
            $message = $e->getMessage();
        }
        if ($message === null) {
            throw new \RuntimeException("no exception for \"$needle\"");
        }
        assertContains($needle, $message);
    }
});

runTest('DML status rows are told apart from data', function () {
    $insert = [['name' => 'number of rows inserted']];
    $update = [['name' => 'number of rows updated'], ['name' => 'number of multi-joined rows updated']];
    $merge = [['name' => 'number of rows inserted'], ['name' => 'number of rows updated']];
    $data = [['name' => 'ID'], ['name' => 'NAME']];
    assertSame(true, Frostlake\isDmlStatus($insert), 'insert shape');
    assertSame(true, Frostlake\isDmlStatus($update), 'update shape');
    assertSame(true, Frostlake\isDmlStatus($merge), 'merge shape');
    assertSame(false, Frostlake\isDmlStatus($data), 'data shape');
    assertSame(false, Frostlake\isDmlStatus([]), 'empty shape');
    assertSame(3, Frostlake\dmlRowCount($insert, [3]), 'insert count');
    // the multi-joined counter is a sub-count of rows already counted as updated
    assertSame(2, Frostlake\dmlRowCount($update, [2, 5]), 'update count');
    assertSame(4, Frostlake\dmlRowCount($merge, [1, 3]), 'merge count');
});

runTest('nanosecond fractions are trimmed to what PHP can parse', function () {
    assertSame('2026-01-02 03:04:05.123456', Frostlake\trimFraction('2026-01-02 03:04:05.123456789', 6));
    assertSame('2026-01-02 03:04:05.12', Frostlake\trimFraction('2026-01-02 03:04:05.12', 6));
    assertSame('2026-01-02 03:04:05', Frostlake\trimFraction('2026-01-02 03:04:05', 6));
    assertSame('12:00:00.123456 +0200', Frostlake\trimFraction('12:00:00.123456789 +0200', 6));
});

runTest('cell conversion follows the column type', function () {
    assertSame(null, Frostlake\convertValue(null, 'DATE'));
    assertSame("\xCA\xFE", Frostlake\convertValue('CAFE', 'BINARY'));
    assertSame('NOTHEX', Frostlake\convertValue('NOTHEX', 'BINARY'), 'non-hex passes through');
    assertSame('ODD1', Frostlake\convertValue('ODD1', 'BINARY'), 'odd length passes through');
    assertSame(7, Frostlake\convertValue(7, 'NUMBER'));
    assertSame('{"k":1}', Frostlake\convertValue('{"k":1}', 'OBJECT'), 'semi-structured keeps wire shape');
    $parsed = Frostlake\convertValue('2026-08-13', 'DATE');
    if (!$parsed instanceof \DateTimeImmutable) {
        throw new \RuntimeException('DATE did not parse');
    }
    assertSame('2026-08-13', $parsed->format('Y-m-d'));
    assertSame('not a date', Frostlake\convertValue('not a date', 'DATE'), 'unparsable text survives');
});

runTest('DSNs are validated before any request', function () {
    $bad = ['ftp://host/db' => 'DSN must start with', 'nonsense' => 'invalid DSN'];
    foreach ($bad as $dsn => $needle) {
        $message = null;
        try {
            new \Frostlake\Connection($dsn);
        } catch (FrostlakeException $e) {
            $message = $e->getMessage();
        }
        if ($message === null) {
            throw new \RuntimeException("no exception for DSN \"$dsn\"");
        }
        assertContains($needle, $message);
    }
    // A well-formed DSN builds without touching the network.
    $conn = new \Frostlake\Connection('frostlake://localhost:18082/MY_DB?schema=PUBLIC');
    assertSame(false, $conn->isClosed());
});

runTest('a closed connection refuses further statements', function () {
    $conn = new \Frostlake\Connection('frostlake://localhost:18082/MY_DB');
    $conn->close();
    assertSame(true, $conn->isClosed());
    $message = null;
    try {
        $conn->execute('SELECT 1');
    } catch (FrostlakeException $e) {
        $message = $e->getMessage();
    }
    assertSame('connection is closed', $message);
});

/**
 * Stands in for PHP's own http:// wrapper, keeping the request bodies the
 * driver hands it and answering each with a bare success; there is no mocking
 * library here.
 */
final class RecordingHttpWrapper
{
    /** @var list<array<string, mixed>> */
    public static array $payloads = [];
    /** @var resource */
    public $context;
    private string $body = '{"success":true,"resultSets":[]}';
    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $http = stream_context_get_options($this->context)['http'] ?? [];
        if (isset($http['content'])) {
            self::$payloads[] = json_decode((string) $http['content'], true);
        }
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->body, $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    /** @return array<string, mixed> */
    public function stream_stat(): array
    {
        return [];
    }
}

/** @return list<array<string, mixed>> the payloads $work's statements sent */
function recordPayloads(callable $work): array
{
    RecordingHttpWrapper::$payloads = [];
    stream_wrapper_unregister('http');
    stream_wrapper_register('http', RecordingHttpWrapper::class);
    try {
        $work(new \Frostlake\Connection('frostlake://127.0.0.1:1'));
    } finally {
        stream_wrapper_restore('http');
    }
    return RecordingHttpWrapper::$payloads;
}

runTest('no multi-statement count is sent unless the call asks for one', function () {
    $payloads = recordPayloads(function (\Frostlake\Connection $conn) {
        $conn->execute('SELECT 1');
        $conn->executeAll('SELECT 1; SELECT 2', [], null);
    });
    assertSame(2, count($payloads), 'request count');
    foreach ($payloads as $payload) {
        assertSame(false, array_key_exists('multiStatementCount', $payload), 'field absent');
    }
});

runTest('a declared count rides on the request that asked for it', function () {
    $payloads = recordPayloads(function (\Frostlake\Connection $conn) {
        $conn->execute('SELECT 1; SELECT 2', [], 2);
        $conn->executeAll('SELECT 1; SELECT 2; SELECT 3', [], 0);
        $conn->execute('SELECT 1');
    });
    assertSame(2, $payloads[0]['multiStatementCount'] ?? null, 'declared count');
    // 0 is a count like any other — any number — not an absent one.
    assertSame(0, $payloads[1]['multiStatementCount'] ?? null, 'any-number count');
    assertSame(false, array_key_exists('multiStatementCount', $payloads[2]), 'back to absent');
    foreach ($payloads as $payload) {
        // Nothing alters session state to carry the count.
        assertSame(false, str_contains((string) $payload['sql'], 'ALTER SESSION'), 'no session change');
    }
});

runTest('a negative multi-statement count is refused before any request', function () {
    $message = null;
    $payloads = recordPayloads(function (\Frostlake\Connection $conn) use (&$message) {
        try {
            $conn->execute('SELECT 1', [], -1);
        } catch (FrostlakeException $e) {
            $message = $e->getMessage();
        }
    });
    assertContains('multi-statement count cannot be negative', (string) $message);
    assertSame(0, count($payloads), 'nothing sent');
});

// -- session tracking ---------------------------------------------------------

runTest('requests split on top-level semicolons only', function () {
    assertSame(["SELECT 1", " SELECT ';'", ' SELECT ";"'], Frostlake\splitStatements("SELECT 1; SELECT ';'; SELECT \";\";"));
    assertSame(1, count(Frostlake\splitStatements('EXECUTE IMMEDIATE $$ SELECT 1; SELECT 2; $$')), 'dollar body');
    assertSame(2, count(Frostlake\splitStatements("SELECT 1 -- a; b\n; SELECT 2")), 'line comment');
    assertSame(1, count(Frostlake\splitStatements('SELECT 1 /* a; b */')), 'block comment');
    assertSame([], Frostlake\splitStatements(' ; '), 'blank pieces');
});

runTest('leading words skip comments and fold case', function () {
    assertSame(['CREATE', 'OR', 'REPLACE'], Frostlake\leadingWords("/* c */ -- x\n create or replace table t", 3));
});

runTest('which statements leave session state behind', function () {
    $cases = [
        'USE SCHEMA s' => true, 'use database d' => true, 'SET x = 1' => true, 'UNSET x' => true,
        "ALTER SESSION SET TIMEZONE = 'UTC'" => true, 'CREATE OR REPLACE DATABASE d' => true,
        'CREATE SCHEMA IF NOT EXISTS s' => true, 'DROP DATABASE d' => true,
        'CREATE TEMPORARY TABLE t (a INT)' => true, 'CREATE OR REPLACE TEMP TABLE t (a INT)' => true,
        'CREATE LOCAL TEMPORARY TABLE t (a INT)' => true, 'CREATE TABLE t (a INT)' => false,
        'CREATE OR REPLACE TRANSIENT TABLE t (a INT)' => false, 'ALTER TABLE t ADD COLUMN b INT' => false,
        'SELECT 1' => false, 'INSERT INTO t VALUES (1)' => false, "SELECT 'USE DATABASE x'" => false,
    ];
    foreach ($cases as $sql => $expected) {
        assertSame($expected, Frostlake\touchesSession($sql), $sql);
    }
});

runTest('transaction control is recognised and a scripting block is not', function () {
    $cases = [
        'BEGIN' => 'begin', 'begin transaction' => 'begin', 'BEGIN WORK' => 'begin', 'BEGIN NAME t1' => 'begin',
        'START TRANSACTION' => 'begin', 'COMMIT' => 'end', 'ROLLBACK WORK' => 'end',
        'BEGIN LET x := 1; RETURN x; END' => null, 'SELECT 1' => null,
    ];
    foreach ($cases as $sql => $expected) {
        assertSame($expected, Frostlake\transactionEffect(Frostlake\splitStatements($sql)[0]), $sql);
    }
});

/**
 * The scripted engine — test/scripted_engine.php under PHP's built-in web
 * server — booted on first use: its address, and the directory holding its
 * script and what it was sent.
 *
 * @return array{string, string}
 */
function scriptedEngine(): array
{
    static $engine = null;
    if ($engine !== null) {
        return $engine;
    }
    $dir = sys_get_temp_dir() . '/frostlake-php-scripted-' . getmypid();
    if (!is_dir($dir) && !mkdir($dir)) {
        throw new \RuntimeException("cannot create $dir");
    }
    file_put_contents("$dir/script.json", '[]');
    file_put_contents("$dir/sent.jsonl", '');
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/scripted_engine.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['FROSTLAKE_SCRIPT' => $dir] + getenv()
    );
    register_shutdown_function(function () use ($server, $dir) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
        foreach (glob("$dir/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir);
    });
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.2);
        if ($socket !== false) {
            fclose($socket);
            $engine = ["frostlake://127.0.0.1:$port", $dir];
            return $engine;
        }
        usleep(50_000);
    }
    throw new \RuntimeException('the scripted engine did not start');
}

/** A connection to the scripted engine, which has nothing scripted and has been sent nothing. */
function scriptedConnection(string $scope = '/APP?schema=PUBLIC'): \Frostlake\Connection
{
    [$address, $dir] = scriptedEngine();
    file_put_contents("$dir/script.json", '[]');
    file_put_contents("$dir/sent.jsonl", '');
    return new \Frostlake\Connection($address . $scope);
}

/** Queues the scripted engine's answer to the next request. */
function reply(string $body, int $status = 200): void
{
    [, $dir] = scriptedEngine();
    $script = json_decode((string) file_get_contents("$dir/script.json"), true) ?: [];
    $script[] = ['status' => $status, 'body' => $body];
    file_put_contents("$dir/script.json", json_encode($script));
}

/** How many scripted answers no request has taken yet. */
function pendingAnswers(): int
{
    [, $dir] = scriptedEngine();
    return count(json_decode((string) file_get_contents("$dir/script.json"), true) ?: []);
}

/** @return list<array{verb: string, path: string, payload: ?array<string, mixed>}> */
function sentRequests(): array
{
    [, $dir] = scriptedEngine();
    $sent = [];
    foreach (file("$dir/sent.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $sent[] = json_decode($line, true);
    }
    return $sent;
}

/** @return list<array<string, mixed>> the payload of every POST /api/execute, in order */
function sentExecutes(): array
{
    $payloads = [];
    foreach (sentRequests() as $sent) {
        if ($sent['path'] === '/api/execute') {
            $payloads[] = $sent['payload'];
        }
    }
    return $payloads;
}

/** @return list<string> the SQL of every POST /api/execute, in order */
function sentStatements(): array
{
    $statements = [];
    foreach (sentExecutes() as $payload) {
        $statements[] = $payload['sql'];
    }
    return $statements;
}

const SCRIPTED_SCOPE = ['USE DATABASE "APP"', 'USE SCHEMA "PUBLIC"'];
/** What openedOnScript() sent. */
const OPENED = [...SCRIPTED_SCOPE, 'SELECT 0'];
const OK_SET = ['columns' => [['dataType' => 'VARCHAR', 'name' => 'status']],
    'rows' => [['Statement executed successfully.']], 'rowCount' => 1, 'updateCount' => -1];

/** @return array<string, mixed> */
function numberSet(string $name, int $value): array
{
    return ['columns' => [['dataType' => 'NUMBER', 'name' => $name, 'precision' => 38, 'scale' => 0]],
        'rows' => [[$value]], 'rowCount' => 1, 'updateCount' => -1];
}

/**
 * An answer from an engine that reports newSession, as 0.1.0 and later do.
 *
 * @param list<array<string, mixed>> $sets
 */
function answer(string $sessionId, bool $started, array $sets = [OK_SET]): string
{
    return (string) json_encode(['success' => true, 'sessionId' => $sessionId, 'newSession' => $started,
        'errorMessage' => null, 'executionTimeMs' => 1, 'resultSets' => $sets]);
}

/**
 * An answer from an engine that predates newSession, requireSession and the release.
 *
 * @param list<array<string, mixed>> $sets
 */
function legacyAnswer(string $sessionId, array $sets = [OK_SET]): string
{
    return (string) json_encode(['success' => true, 'sessionId' => $sessionId, 'errorMessage' => null,
        'executionTimeMs' => 1, 'resultSets' => $sets]);
}

function refusedAnswer(string $sessionId, string $message): string
{
    return (string) json_encode(['success' => false, 'sessionId' => $sessionId, 'newSession' => false,
        'errorMessage' => $message, 'executionTimeMs' => 0, 'resultSets' => []]);
}

/** The 404 a request that requires its session gets once the session is gone. */
function sessionGone(string $sessionId): string
{
    return (string) json_encode(['success' => false, 'sessionId' => null, 'newSession' => false,
        'errorMessage' => "Session '$sessionId' does not exist or has expired.", 'executionTimeMs' => 0,
        'resultSets' => []]);
}

const RELEASED = '{"success":true,"sessionId":null,"newSession":false,"errorMessage":null,"resultSets":[]}';

/**
 * A connection that ran its first statement, SELECT 0, on session s1 of a
 * scripted engine that reports newSession: the DSN's scope went on first.
 */
function openedOnScript(): \Frostlake\Connection
{
    $conn = scriptedConnection();
    reply(answer('s1', true));
    reply(answer('s1', false));
    reply(answer('s1', false, [numberSet('N', 0)]));
    $conn->execute('SELECT 0');
    return $conn;
}

/** The FrostlakeException $work throws, or a failure when it throws none. */
function thrownBy(callable $work): FrostlakeException
{
    try {
        $work();
    } catch (FrostlakeException $e) {
        return $e;
    }
    throw new \RuntimeException('no FrostlakeException was thrown');
}

runTest('requireSession waits until the engine says it tracks sessions', function () {
    $conn = openedOnScript();
    [$first, $second] = sentExecutes();
    // The first request names no session, so there is nothing to require yet.
    assertSame(false, array_key_exists('sessionId', $first), 'first names no session');
    assertSame(false, array_key_exists('requireSession', $first), 'first requires nothing');
    // Its answer carried newSession: every request from then on requires its session.
    assertSame('s1', $second['sessionId'] ?? null, 'second names the session');
    assertSame(true, $second['requireSession'] ?? null, 'second requires it');
    reply(answer('s1', false, [numberSet('N', 1)]));
    assertSame([['N' => 1]], $conn->execute('SELECT 1 AS N')->rows);
    assertSame(true, sentExecutes()[3]['requireSession'] ?? null, 'statements require it too');
});

runTest('an older engine is sent neither requireSession nor a release', function () {
    $conn = scriptedConnection();
    reply(legacyAnswer('old1'));
    reply(legacyAnswer('old1'));
    reply(legacyAnswer('old1', [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    $ids = [];
    foreach (sentExecutes() as $payload) {
        $ids[] = $payload['sessionId'] ?? null;
        assertSame(false, array_key_exists('requireSession', $payload), 'no requireSession');
    }
    assertSame([null, 'old1', 'old1'], $ids, 'session ids');
    $conn->close();
    assertSame(3, count(sentRequests()), 'closing sent nothing');
});

runTest('a lost session is replaced on the DSN scope and the statement sent once more', function () {
    $conn = openedOnScript();
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    assertSame([['N' => 1]], $conn->execute('SELECT 1 AS N')->rows);
    assertSame(
        [...OPENED, 'SELECT 1 AS N', ...SCRIPTED_SCOPE, 'SELECT 1 AS N'],
        sentStatements()
    );
    $executes = sentExecutes();
    // The scope went onto a fresh session: its first request named none.
    assertSame(false, array_key_exists('sessionId', $executes[4]), 'fresh session');
    assertSame('s2', $executes[6]['sessionId'] ?? null, 'statement on the fresh session');
    assertSame(0, pendingAnswers(), 'every answer taken');
});

runTest('a second refusal throws instead of trying again', function () {
    $conn = openedOnScript();
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(sessionGone('s2'), 404);
    $e = thrownBy(fn () => $conn->execute('SELECT 1 AS N'));
    assertSame(FrostlakeException::SESSION_LOST, $e->getCode(), 'code');
    assertSame(
        [...OPENED, 'SELECT 1 AS N', ...SCRIPTED_SCOPE, 'SELECT 1 AS N'],
        sentStatements()
    );
    assertSame(0, pendingAnswers(), 'every answer taken');
});

runTest('a lost session with an open transaction is reported, not replaced', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->beginTransaction();
    reply(sessionGone('s1'), 404);
    $e = thrownBy(fn () => $conn->execute('INSERT INTO t VALUES (1)'));
    assertSame(FrostlakeException::SESSION_LOST, $e->getCode(), 'code');
    assertContains('transaction', $e->getMessage());
    assertSame([...OPENED, 'BEGIN', 'INSERT INTO t VALUES (1)'], sentStatements());
    // The connection stays usable: the next statement starts over on the DSN's
    // scope, outside any transaction.
    assertSame(true, $conn->isAutoCommit(), 'autocommit back on');
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    $executes = sentExecutes();
    assertSame([...SCRIPTED_SCOPE, 'SELECT 1 AS N'], array_slice(sentStatements(), -3));
    assertSame(false, array_key_exists('sessionId', $executes[5]), 'fresh session');
    assertSame(true, $executes[7]['autoCommit'], 'outside any transaction');
    assertSame(0, pendingAnswers(), 'every answer taken');
});

runTest('a BEGIN that meets a lost session opens the transaction on the fresh one', function () {
    $conn = openedOnScript();
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false));
    $conn->beginTransaction();
    assertSame([...OPENED, 'BEGIN', ...SCRIPTED_SCOPE, 'BEGIN'], sentStatements());
    assertSame(false, sentExecutes()[6]['autoCommit'], 'BEGIN sent with autocommit off');
    // Now it holds a transaction, so losing this session too is reported.
    reply(sessionGone('s2'), 404);
    assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->execute('SELECT 1'))->getCode());
});

runTest('a transaction opened by a statement is tracked until it ends', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->execute('begin transaction');
    reply(sessionGone('s1'), 404);
    assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->execute('SELECT 1'))->getCode());

    $conn = openedOnScript();
    reply(answer('s1', false));
    reply(answer('s1', false));
    $conn->execute('START TRANSACTION');
    $conn->execute('COMMIT');
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    assertSame([['N' => 1]], $conn->execute('SELECT 1 AS N')->rows);
});

runTest('a lost session whose context moved is reported, not replaced', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->execute('USE SCHEMA OTHER');
    reply(sessionGone('s1'), 404);
    $e = thrownBy(fn () => $conn->execute('SELECT * FROM t'));
    assertSame(FrostlakeException::SESSION_LOST, $e->getCode(), 'code');
    assertContains('context', $e->getMessage());
    assertSame([...OPENED, 'USE SCHEMA OTHER', 'SELECT * FROM t'], sentStatements());
    // Then back on the DSN's scope, not the schema the lost session had moved to.
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    assertSame([...SCRIPTED_SCOPE, 'SELECT 1 AS N'], array_slice(sentStatements(), -3));
    assertSame(0, pendingAnswers(), 'every answer taken');
});

runTest('session state is noticed anywhere in a request', function () {
    $statements = ['SET x = 1', "ALTER SESSION SET TIMEZONE = 'UTC'", 'CREATE TEMPORARY TABLE t (a INT)',
        'CREATE DATABASE other', 'UNSET x'];
    foreach ($statements as $statement) {
        $conn = openedOnScript();
        reply(answer('s1', false, [numberSet('N', 1), OK_SET]));
        $conn->executeAll("SELECT 1 AS N; $statement", [], 2);
        reply(sessionGone('s1'), 404);
        assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->execute('SELECT 2'))->getCode(), $statement);
        assertSame(0, pendingAnswers(), $statement);
    }
});

runTest('a refused statement leaves the session as it was', function () {
    $conn = openedOnScript();
    reply(refusedAnswer('s1', "Schema 'NOPE' does not exist or not authorized."));
    thrownBy(fn () => $conn->execute('USE SCHEMA NOPE'));
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    assertSame([['N' => 1]], $conn->execute('SELECT 1 AS N')->rows);
});

runTest('a scope that fails on the fresh session stays pending in full', function () {
    $conn = openedOnScript();
    reply(sessionGone('s1'), 404);
    reply(refusedAnswer('s2', "Database 'APP' does not exist or not authorized."));
    assertSame(0, thrownBy(fn () => $conn->execute('SELECT 1 AS N'))->getCode(), 'the refusal itself');
    // Not USE SCHEMA alone: the next statement puts the whole scope on first.
    reply(answer('s2', false));
    reply(answer('s2', false));
    reply(answer('s2', false, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    assertSame(
        [...OPENED, 'SELECT 1 AS N', SCRIPTED_SCOPE[0], ...SCRIPTED_SCOPE, 'SELECT 1 AS N'],
        sentStatements()
    );
});

runTest('a session the engine replaced gets its scope back before the next statement', function () {
    $conn = openedOnScript();
    reply(answer('s1', true, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    reply(answer('s1', false));
    reply(answer('s1', false));
    reply(answer('s1', false, [numberSet('N', 2)]));
    $conn->execute('SELECT 2 AS N');
    assertSame(
        [...OPENED, 'SELECT 1 AS N', ...SCRIPTED_SCOPE, 'SELECT 2 AS N'],
        sentStatements()
    );
});

runTest('with no scope in the DSN the statement itself starts the fresh session', function () {
    $conn = scriptedConnection('');
    reply(answer('n1', true, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    reply(sessionGone('n1'), 404);
    reply(answer('n2', true, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    assertSame(['SELECT 1 AS N', 'SELECT 1 AS N', 'SELECT 1 AS N'], sentStatements());
    assertSame(false, array_key_exists('sessionId', sentExecutes()[2]), 'fresh session');
});

runTest('closing releases the session once', function () {
    $conn = openedOnScript();
    reply(RELEASED);
    $conn->close();
    $sent = sentRequests();
    $release = $sent[count($sent) - 1];
    assertSame(['DELETE', '/api/sessions/s1'], [$release['verb'], $release['path']]);
    assertSame(0, pendingAnswers(), 'every answer taken');
    $conn->close();
    assertSame(count($sent), count(sentRequests()), 'closing again sent nothing');
    assertSame('connection is closed', thrownBy(fn () => $conn->execute('SELECT 1'))->getMessage());
});

runTest('closing with a transaction open leaves the rollback to the release', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->beginTransaction();
    reply(RELEASED);
    $conn->close();
    $verbs = array_column(sentRequests(), 'verb');
    assertSame(['POST', 'DELETE'], array_slice($verbs, -2));
    assertSame(0, pendingAnswers(), 'every answer taken');
});

runTest('closing never throws whatever the release meets', function () {
    // A session already gone, and an engine without the endpoint.
    foreach ([[sessionGone('s1'), 404], ['<html><body>405 Method Not Allowed</body></html>', 405]] as [$body, $status]) {
        $conn = openedOnScript();
        reply($body, $status);
        $conn->close();
        assertSame(true, $conn->isClosed(), "closed after $status");
        assertSame(0, pendingAnswers(), "the release was sent ($status)");
    }
    // Nothing listening, and a listener that never answers: bounded either way.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $refused = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    $stalled = (int) explode(':', stream_socket_get_name($silent, false))[1];
    try {
        foreach ([$refused, $stalled] as $port) {
            $conn = new \Frostlake\Connection("frostlake://127.0.0.1:$port/APP");
            (function () {
                $this->sessionId = 's1';
                $this->tracksSessions = true;
            })->call($conn);
            $started = microtime(true);
            $conn->close();
            $took = microtime(true) - $started;
            assertSame(true, $conn->isClosed(), "closed ($port)");
            if ($took > 8.0) {
                throw new \RuntimeException(sprintf('the release took %.1fs', $took));
            }
        }
    } finally {
        fclose($silent);
    }
});

runTest('a DSN names its database and schema the way unquoted SQL does', function () {
    $cases = [
        // A plain name folds to upper case, as it would unquoted.
        '/my_db?schema=my_schema' => ['USE DATABASE "MY_DB"', 'USE SCHEMA "MY_SCHEMA"'],
        '/Mixed_1$x?schema=PUBLIC' => ['USE DATABASE "MIXED_1$X"', 'USE SCHEMA "PUBLIC"'],
        // A reserved word is still a plain name; quoting keeps it one.
        '/select' => ['USE DATABASE "SELECT"'],
        // One written in double quotes is used as it stands, for an exact-case match.
        '/%22my%20Db%22?schema=%22low%22' => ['USE DATABASE "my Db"', 'USE SCHEMA "low"'],
        // Anything else is quoted as given.
        '/1abc?schema=my-schema' => ['USE DATABASE "1abc"', 'USE SCHEMA "my-schema"'],
        '/a%22b' => ['USE DATABASE "a""b"'],
    ];
    foreach ($cases as $scope => $expected) {
        $conn = scriptedConnection($scope);
        foreach ($expected as $i => $statement) {
            reply(answer('s1', $i === 0));
        }
        reply(answer('s1', false, [numberSet('N', 1)]));
        $conn->execute('SELECT 1 AS N');
        assertSame([...$expected, 'SELECT 1 AS N'], sentStatements(), $scope);
    }
});

// -- a begin that fails -------------------------------------------------------
// It opens no transaction, so the connection stays in autocommit and the
// statements after it commit as they run. BEGIN itself goes out with
// autocommit off.

/** Stands in for PHP's own http:// wrapper and fails every request in transit, as a hang-up does. */
final class FailingHttpWrapper
{
    /** @var resource */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}

function withFailingTransport(callable $work): void
{
    stream_wrapper_unregister('http');
    stream_wrapper_register('http', FailingHttpWrapper::class);
    try {
        $work();
    } finally {
        stream_wrapper_restore('http');
    }
}

/** The body the connection's next statement goes out with, once its answer is scripted. */
function nextStatementSent(\Frostlake\Connection $conn, string $session = 's1'): array
{
    reply(answer($session, false, [numberSet('N', 1)]));
    $conn->execute('SELECT 1 AS N');
    $executes = sentExecutes();
    return $executes[count($executes) - 1];
}

/** @return list<bool> the autoCommit every BEGIN went out with */
function beginsSent(): array
{
    $sent = [];
    foreach (sentExecutes() as $payload) {
        if ($payload['sql'] === 'BEGIN') {
            $sent[] = $payload['autoCommit'];
        }
    }
    return $sent;
}

runTest('a begin the engine refuses leaves autocommit on', function () {
    $conn = openedOnScript();
    reply(refusedAnswer('s1', "SQL compilation error:\nsyntax error line 1 at position 0 unexpected 'BEGIN'."));
    thrownBy(fn () => $conn->beginTransaction());
    assertSame([false], beginsSent(), 'BEGIN went out with autocommit off');
    assertSame(true, $conn->isAutoCommit(), 'isAutoCommit');
    assertSame(true, nextStatementSent($conn)['autoCommit'], 'the next statement');
});

runTest('a begin answered with an unreadable body leaves autocommit on', function () {
    $conn = openedOnScript();
    reply('<html><body>502 Bad Gateway</body></html>', 502);
    assertContains('unreadable', thrownBy(fn () => $conn->beginTransaction())->getMessage());
    assertSame(true, nextStatementSent($conn)['autoCommit'], 'the next statement');
});

runTest('a begin that fails in transit leaves autocommit on', function () {
    $conn = openedOnScript();
    $e = null;
    withFailingTransport(function () use ($conn, &$e) {
        $e = thrownBy(fn () => $conn->beginTransaction());
    });
    assertContains('request failed', $e->getMessage());
    assertSame(true, $conn->isAutoCommit(), 'isAutoCommit');
    assertSame(true, nextStatementSent($conn)['autoCommit'], 'the next statement');
});

runTest('a begin behind a refused queued USE is never sent and leaves autocommit on', function () {
    $conn = scriptedConnection();
    reply(refusedAnswer('s1', "Database 'APP' does not exist or not authorized."));
    thrownBy(fn () => $conn->beginTransaction());
    assertSame([SCRIPTED_SCOPE[0]], sentStatements(), 'no BEGIN');
    reply(answer('s1', false));
    reply(answer('s1', false));
    nextStatementSent($conn);
    assertSame([SCRIPTED_SCOPE[0], ...SCRIPTED_SCOPE, 'SELECT 1 AS N'], sentStatements(), 'statements');
    assertSame([true, true, true, true], array_column(sentExecutes(), 'autoCommit'), 'every request autocommits');
});

runTest('a begin whose lost session cannot be replaced leaves autocommit on', function () {
    $conn = openedOnScript();
    reply(sessionGone('s1'), 404);
    reply(answer('s2', true));
    reply(answer('s2', false));
    reply(sessionGone('s2'), 404);
    assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->beginTransaction())->getCode());
    assertSame([false, false], beginsSent(), 'sent again on the fresh session, still with autocommit off');
    assertSame(true, nextStatementSent($conn, 's2')['autoCommit'], 'the next statement');
});

runTest('a begin whose lost session held context leaves autocommit on', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->execute('USE SCHEMA OTHER');
    reply(sessionGone('s1'), 404);
    assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->beginTransaction())->getCode());
    reply(answer('s2', true));
    reply(answer('s2', false));
    assertSame(true, nextStatementSent($conn, 's2')['autoCommit'], 'the next statement');
});

runTest('transaction() whose begin fails rolls back and leaves autocommit on', function () {
    $conn = openedOnScript();
    reply(refusedAnswer('s1', 'begin refused'));
    reply(answer('s1', false));
    $ran = false;
    assertSame('begin refused', thrownBy(fn () => $conn->transaction(function () use (&$ran) {
        $ran = true;
    }))->getMessage());
    assertSame(false, $ran, 'the work never ran');
    assertSame(true, $conn->isAutoCommit(), 'isAutoCommit');
    assertSame(['BEGIN', 'ROLLBACK'], array_slice(sentStatements(), -2), 'a best-effort ROLLBACK after it');
    assertSame(true, nextStatementSent($conn)['autoCommit'], 'the next statement');
});

runTest('a begin that succeeds turns autocommit off', function () {
    $conn = openedOnScript();
    reply(answer('s1', false));
    $conn->beginTransaction();
    assertSame([false], beginsSent(), 'BEGIN went out with autocommit off');
    assertSame(false, $conn->isAutoCommit(), 'isAutoCommit');
    assertSame(false, nextStatementSent($conn)['autoCommit'], 'the next statement');
});

// -- integration tests --------------------------------------------------------

runTest('ddl, dml and typed query', function () {
    $conn = openOrSkip('php_test_db');
    $conn->execute('CREATE TABLE people (id INTEGER, name VARCHAR, score FLOAT, ok BOOLEAN)');
    $inserted = $conn->execute(
        'INSERT INTO people VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
        [1, "Ada O'Hara \\ Byron", 9.5, true, 2, 'Grace', 8.25, false]
    );
    assertSame(2, $inserted->rowCount, 'insert count');
    $result = $conn->execute('SELECT id, name, score, ok FROM people WHERE id = ?', [1]);
    assertSame(
        [['ID' => 1, 'NAME' => "Ada O'Hara \\ Byron", 'SCORE' => 9.5, 'OK' => true]],
        $result->rows
    );
    $conn->close();
});

runTest('session state persists across statements', function () {
    $conn = openOrSkip('php_sess_db');
    $conn->execute('CREATE TABLE t1 (a INTEGER)');
    $conn->execute('INSERT INTO t1 VALUES (7)');
    assertSame([['A' => 7]], $conn->execute('SELECT a FROM t1')->rows);
    $conn->close();
});

runTest('transaction closure rolls back on error', function () {
    $conn = openOrSkip('php_tx_db');
    $conn->execute('CREATE TABLE acc (n INTEGER)');
    $conn->execute('INSERT INTO acc VALUES (1)');
    $thrown = false;
    try {
        $conn->transaction(function ($c) {
            $c->execute('INSERT INTO acc VALUES (2)');
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
        $thrown = true;
    }
    assertSame(true, $thrown, 'exception propagated');
    assertSame([['N' => 1]], $conn->execute('SELECT COUNT(*) AS n FROM acc')->rows);
    $conn->close();
});

runTest('temporal round trip', function () {
    $conn = openOrSkip('php_ts_db');
    // A bound date-time is a TIMESTAMP_TZ, which a TIMESTAMP_TZ column keeps as it is: the wall
    // clock and the offset it was written with. A DATE column takes it too.
    $conn->execute('CREATE TABLE stamps (id INTEGER, moment TIMESTAMP_TZ, d DATE)');
    $moment = new \DateTimeImmutable('2026-08-13 12:34:56.789000+01:00');
    $conn->execute('INSERT INTO stamps VALUES (?, ?, ?)', [1, $moment, new \DateTimeImmutable('2026-08-13')]);
    $row = $conn->execute('SELECT moment, d FROM stamps WHERE id = 1')->rows[0];
    if (!$row['MOMENT'] instanceof \DateTimeImmutable) {
        throw new \RuntimeException('MOMENT is not DateTimeImmutable');
    }
    assertSame('2026-08-13 12:34:56.789000 +01:00', $row['MOMENT']->format('Y-m-d H:i:s.u P'));
    assertSame('2026-08-13', $row['D']->format('Y-m-d'));
    $conn->close();
});

runTest('a bound date-time reaches an NTZ column through a cast', function () {
    $conn = openOrSkip('php_ts_ntz_db');
    $conn->execute('CREATE TABLE naive (id INTEGER, moment TIMESTAMP_NTZ)');
    $moment = new \DateTimeImmutable('2026-08-13 12:34:56.789000+01:00');
    // The account refuses a TIMESTAMP_TZ written into a TIMESTAMP_NTZ column while compiling, so a
    // bare bound date-time is refused there too.
    $message = null;
    try {
        $conn->execute('INSERT INTO naive VALUES (?, ?)', [1, $moment]);
    } catch (FrostlakeException $e) {
        $message = $e->getMessage();
    }
    assertContains('expecting TIMESTAMP_NTZ(9) but got TIMESTAMP_TZ(9)', (string) $message);
    // Cast, it keeps the wall clock the date-time was written with.
    $conn->execute('INSERT INTO naive VALUES (?, CAST(? AS TIMESTAMP_NTZ))', [1, $moment]);
    $stored = $conn->execute('SELECT moment FROM naive WHERE id = 1')->rows[0]['MOMENT'];
    assertSame('2026-08-13 12:34:56.789000', $stored->format('Y-m-d H:i:s.u'));
    $conn->close();
});

runTest('big integers stay exact digit strings', function () {
    $conn = openOrSkip('php_big_db');
    $result = $conn->execute("SELECT 12345678901234567890123456789::NUMBER(38,0) AS n");
    assertSame('12345678901234567890123456789', $result->rows[0]['N']);
    $conn->close();
});

runTest('error surface carries the engine message', function () {
    $conn = openOrSkip('php_err_db');
    $message = null;
    try {
        $conn->execute('SELECT FROM nowhere');
    } catch (FrostlakeException $e) {
        $message = $e->getMessage();
    }
    if ($message === null) {
        throw new \RuntimeException('no exception raised');
    }
    assertContains('SQL compilation error', $message);
    $conn->close();
});

runTest('update and merge report rows affected, not rows returned', function () {
    // UPDATE answers with two counters and MERGE with two more, so a driver
    // that only recognises the single-column INSERT shape hands back the
    // status row as data and reports a rowCount of 1.
    $conn = openOrSkip('php_dml_db');
    $conn->execute('CREATE TABLE t (n INTEGER)');
    assertSame(3, $conn->execute('INSERT INTO t VALUES (?), (?), (?)', [1, 2, 3])->rowCount, 'insert');
    $updated = $conn->execute('UPDATE t SET n = n + 10 WHERE n > ?', [1]);
    assertSame(2, $updated->rowCount, 'update count');
    assertSame([], $updated->rows, 'update returns no data rows');
    $merged = $conn->execute(
        'MERGE INTO t USING (SELECT 12 AS n) s ON t.n = s.n '
        . 'WHEN MATCHED THEN UPDATE SET t.n = 99 '
        . 'WHEN NOT MATCHED THEN INSERT (n) VALUES (s.n)'
    );
    assertSame(1, $merged->rowCount, 'merge count');
    assertSame(1, $conn->execute('DELETE FROM t WHERE n = ?', [99])->rowCount, 'delete count');
    $conn->close();
});

runTest('executeAll returns every result set of a multi-statement script', function () {
    $conn = openOrSkip('php_multi_db');
    // A pack has to be asked for: a session takes one statement per request until it says otherwise.
    $conn->execute('ALTER SESSION SET MULTI_STATEMENT_COUNT = 0');
    $results = $conn->executeAll('SELECT ? AS a; SELECT ? AS b;', [1, 2]);
    assertSame(2, count($results), 'result set count');
    assertSame([['A' => 1]], $results[0]->rows);
    assertSame([['B' => 2]], $results[1]->rows);
    // execute() keeps handing back the first one.
    assertSame([['A' => 1]], $conn->execute('SELECT 1 AS a; SELECT 2 AS b;')->rows);
    $conn->close();
});

runTest('a pack can declare its own count without asking the session', function () {
    $conn = openOrSkip('php_per_call_db');
    // No ALTER SESSION anywhere: the count rides on the call that needs it.
    $results = $conn->executeAll('SELECT 1 AS a; SELECT 2 AS b;', [], 2);
    assertSame(2, count($results), 'result set count');
    assertSame([['B' => 2]], $results[1]->rows);
    // 0 means any number.
    assertSame(3, count($conn->executeAll('SELECT 1; SELECT 2; SELECT 3;', [], 0)), 'any number');
    // Only an engine carrying the statement-count gate refuses a pack at all, and this
    // driver supports older ones. Against one of those no refusal ever comes, so the
    // checks below are SKIPPED rather than passed: a green tick would claim an engine
    // had been checked for a refusal it does not make.
    $gated = false;
    try {
        $conn->executeAll('SELECT 1 AS a; SELECT 2 AS b;');
    } catch (FrostlakeException) {
        $gated = true;
    }
    if (!$gated) {
        $conn->close();
        throw new SkippedTest('this engine accepts a pack nobody asked for, so it has no refusal to assert');
    }
    // A count the call does not hold is refused, in either direction.
    $mismatch = null;
    try {
        $conn->execute('SELECT 1', [], 2);
    } catch (FrostlakeException $e) {
        $mismatch = $e->getMessage();
    }
    assertContains('did not match the desired statement count 2', (string) $mismatch);
    // The session's own count is untouched by all of that, so a pack that
    // declares nothing still fails on a session that never asked for one.
    $unasked = null;
    try {
        $conn->executeAll('SELECT 1 AS a; SELECT 2 AS b;');
    } catch (FrostlakeException $e) {
        $unasked = $e->getMessage();
    }
    assertContains('did not match the desired statement count 1', (string) $unasked);
    $conn->close();
});

runTest('$$ bodies survive binding on the way to the engine', function () {
    $conn = openOrSkip('php_dollar_db');
    $row = $conn->execute('SELECT $$a ? b$$ AS s, ? AS n', [5])->rows[0];
    assertSame('a ? b', $row['S'], 'dollar-quoted body untouched');
    assertSame(5, $row['N'], 'placeholder outside still bound');
    $conn->close();
});

runTest('binary, null and array binds round trip', function () {
    $conn = openOrSkip('php_types_db');
    $conn->execute('CREATE TABLE vals (id INTEGER, b BINARY, s VARCHAR, a ARRAY)');
    // INSERT ... SELECT, not VALUES: the engine's VALUES clause takes literals
    // and casts but rejects an array constructor.
    $conn->execute('INSERT INTO vals SELECT ?, ?, ?, ?', [1, new Binary("\xCA\xFE"), null, [1, 'a']]);
    $row = $conn->execute('SELECT b, s, a FROM vals WHERE id = ?', [1])->rows[0];
    assertSame("\xCA\xFE", $row['B'], 'binary comes back as raw bytes');
    assertSame(null, $row['S'], 'null stays null');
    assertContains('1', (string) $row['A']);
    $conn->close();
});

runTest('floats keep their precision across the wire', function () {
    $conn = openOrSkip('php_float_db');
    $row = $conn->execute('SELECT ?::FLOAT AS f', [0.1 + 0.2])->rows[0];
    assertSame(0.1 + 0.2, $row['F'], 'float round trip');
    $conn->close();
});

runTest('duplicate column names stay readable positionally', function () {
    // A hash cannot hold two columns of the same name, so values is the
    // lossless view of what the wire delivered.
    $conn = openOrSkip('php_dup_db');
    $result = $conn->execute('SELECT 1 AS x, 2 AS x');
    assertSame([[1, 2]], $result->values, 'positional cells');
    assertSame([['X' => 2]], $result->rows, 'keyed rows collapse');
    $conn->close();
});

runTest('columns carry the type metadata the wire sends', function () {
    $conn = openOrSkip('php_meta_db');
    $column = $conn->execute('SELECT 1::NUMBER(38,10) AS d')->columns[0];
    assertSame('D', $column['name']);
    assertSame('NUMBER', $column['dataType']);
    assertSame(38, $column['precision']);
    assertSame(10, $column['scale']);
    $conn->close();
});

runTest('text and binary columns carry their declared length', function () {
    // The account reports this number as both the column's precision and its
    // display size; it is characters for text and bytes for binary. Nothing
    // else carries one, and an unbounded column carries the type's maximum
    // rather than nothing.
    $conn = openOrSkip('php_length_db');
    $conn->execute('CREATE TABLE widths (s VARCHAR(9), b BINARY(5), big VARCHAR, n NUMBER(10,2))');
    $columns = $conn->execute('SELECT s, b, big, n FROM widths')->columns;
    // An engine that predates the field sends no width at all, and this driver
    // supports those: with nothing to report the checks below are SKIPPED rather
    // than passed, so a green tick never claims a width the wire never carried.
    if ($columns[0]['length'] === null) {
        $conn->close();
        throw new SkippedTest('this engine sends no column length');
    }
    assertSame(9, $columns[0]['length'], 'VARCHAR(9)');
    assertSame(5, $columns[1]['length'], 'BINARY(5)');
    assertSame(16777216, $columns[2]['length'], 'unbounded VARCHAR');
    assertSame(null, $columns[3]['length'], 'NUMBER carries no length');
    $conn->close();
});

runTest('a length the server never sent stays null rather than zero', function () {
    // An engine that predates the field says nothing, and "unknown" must not
    // read back as a width of 0.
    $conn = openOrSkip('php_length_absent_db');
    $column = $conn->execute('SELECT 1 AS n')->columns[0];
    assertSame(null, $column['length']);
    $conn->close();
});

runTest('explicit begin and commit keep the work', function () {
    $conn = openOrSkip('php_commit_db');
    $conn->execute('CREATE TABLE acc (n INTEGER)');
    $conn->beginTransaction();
    $conn->execute('INSERT INTO acc VALUES (?)', [1]);
    $conn->commit();
    assertSame([['N' => 1]], $conn->execute('SELECT COUNT(*) AS n FROM acc')->rows, 'committed');
    $conn->beginTransaction();
    $conn->execute('INSERT INTO acc VALUES (?)', [2]);
    $conn->rollback();
    assertSame([['N' => 1]], $conn->execute('SELECT COUNT(*) AS n FROM acc')->rows, 'rolled back');
    $conn->close();
});

runTest('a database from the DSN is quoted on the way into USE', function () {
    global $dsn;
    if ($dsn === null) {
        throw new SkippedTest('FROSTLAKE_CLASSPATH not set');
    }
    // 1PHP_DSN_DB starts with a digit, so USE DATABASE 1PHP_DSN_DB is a syntax
    // error; the DSN's name has to reach the engine quoted.
    $conn = Frostlake\connect($dsn);
    $conn->execute('CREATE OR REPLACE DATABASE "1PHP_DSN_DB"');
    $conn->close();
    $scoped = Frostlake\connect($dsn . '/1PHP_DSN_DB?schema=PUBLIC');
    $scoped->execute('CREATE TABLE t (n INTEGER)');
    assertSame(1, $scoped->execute('INSERT INTO t VALUES (?)', [1])->rowCount);
    assertSame([['N' => 1]], $scoped->execute('SELECT n FROM t')->rows);
    $scoped->close();
});

runTest('a failed COMMIT still leaves the connection in autocommit', function () {
    // Otherwise every later statement runs inside a transaction nobody closes,
    // and the writes are never durable.
    foreach (['commit', 'rollback'] as $ending) {
        $conn = openOrSkip('php_autocommit_db');
        assertSame(true, $conn->isAutoCommit(), 'starts in autocommit');
        $conn->beginTransaction();
        assertSame(false, $conn->isAutoCommit(), 'transaction suspends autocommit');
        $conn->close(); // makes the ending statement fail without reaching the server
        $threw = false;
        try {
            $conn->$ending();
        } catch (FrostlakeException) {
            $threw = true;
        }
        assertSame(true, $threw, "$ending propagated the failure");
        assertSame(true, $conn->isAutoCommit(), "$ending restored autocommit");
    }
});

// -- a session the engine let go of --------------------------------------------
// Released behind the connection's back, the way the engine's idle expiry or a
// restart loses it.

function engineUrl(): string
{
    global $dsn;
    if ($dsn === null) {
        throw new SkippedTest('FROSTLAKE_CLASSPATH not set');
    }
    return str_replace('frostlake://', 'http://', $dsn);
}

/** Releases a session behind its connection's back; answers the HTTP status. */
function releaseBehindTheConnection(string $sessionId): int
{
    $context = stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true, 'timeout' => 10]]);
    file_get_contents(engineUrl() . '/api/sessions/' . rawurlencode($sessionId), false, $context);
    preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $status);
    return (int) ($status[1] ?? 0);
}

function activeSessions(): int
{
    return (int) json_decode((string) file_get_contents(engineUrl() . '/api/sessions'), true)['activeSessions'];
}

function sessionOf(\Frostlake\Connection $conn): ?string
{
    return (fn () => $this->sessionId)->call($conn);
}

/** A connection whose DSN names a fresh database, its session started. */
function onDatabase(string $name): \Frostlake\Connection
{
    $setup = Frostlake\connect(engineUrl());
    $setup->execute("CREATE OR REPLACE DATABASE $name");
    $setup->close();
    $conn = Frostlake\connect(engineUrl() . "/$name");
    $conn->execute('SELECT 1');
    return $conn;
}

runTest('a released session is replaced on the DSN scope', function () {
    $conn = onDatabase('PHP_LOST_DB');
    $lost = (string) sessionOf($conn);
    assertSame(200, releaseBehindTheConnection($lost), 'released');
    assertSame([['D' => 'PHP_LOST_DB']], $conn->execute('SELECT CURRENT_DATABASE() AS d')->rows);
    assertSame(true, sessionOf($conn) !== $lost, 'a fresh session');
    $conn->close();
});

runTest('a released session with a transaction open throws session-lost', function () {
    $conn = onDatabase('PHP_LOST_TX_DB');
    $conn->execute('CREATE TABLE t (n INTEGER)');
    $conn->beginTransaction();
    $conn->execute('INSERT INTO t VALUES (1)');
    releaseBehindTheConnection((string) sessionOf($conn));
    $e = thrownBy(fn () => $conn->execute('INSERT INTO t VALUES (2)'));
    assertSame(FrostlakeException::SESSION_LOST, $e->getCode(), 'code');
    assertContains('transaction', $e->getMessage());
    // The release rolled the first INSERT back and the second never ran; the
    // connection carries on in a fresh session on the DSN's scope.
    assertSame(
        [['D' => 'PHP_LOST_TX_DB', 'N' => 0]],
        $conn->execute('SELECT CURRENT_DATABASE() AS d, COUNT(*) AS n FROM t')->rows
    );
    $conn->close();
});

runTest('a released session whose context moved throws session-lost', function () {
    $conn = onDatabase('PHP_LOST_USE_DB');
    $conn->execute('USE SCHEMA PUBLIC');
    releaseBehindTheConnection((string) sessionOf($conn));
    $e = thrownBy(fn () => $conn->execute('SELECT 1'));
    assertSame(FrostlakeException::SESSION_LOST, $e->getCode(), 'code');
    assertContains('context', $e->getMessage());
    assertSame([['D' => 'PHP_LOST_USE_DB']], $conn->execute('SELECT CURRENT_DATABASE() AS d')->rows);
    $conn->close();
});

runTest('closing releases the session', function () {
    $conn = Frostlake\connect(engineUrl());
    $conn->execute('SELECT 1');
    $before = activeSessions();
    $conn->close();
    assertSame($before - 1, activeSessions(), 'active sessions');
});

runTest('a lower-case DSN database and schema select what the unquoted names do', function () {
    $setup = Frostlake\connect(engineUrl());
    $setup->execute('CREATE OR REPLACE DATABASE php_lower_db');
    $setup->execute('CREATE SCHEMA php_lower_db.php_lower_schema');
    $setup->execute('CREATE OR REPLACE DATABASE "phpExact"');
    $setup->close();
    $lower = Frostlake\connect(engineUrl() . '/php_lower_db?schema=php_lower_schema');
    assertSame(
        [['D' => 'PHP_LOWER_DB', 'S' => 'PHP_LOWER_SCHEMA']],
        $lower->execute('SELECT CURRENT_DATABASE() AS d, CURRENT_SCHEMA() AS s')->rows
    );
    $lower->close();
    // Written in double quotes, a name keeps its case.
    $exact = Frostlake\connect(engineUrl() . '/%22phpExact%22');
    assertSame([['D' => 'phpExact']], $exact->execute('SELECT CURRENT_DATABASE() AS d')->rows);
    $exact->close();
});

runTest('writes after a failed begin are committed', function () {
    // The begin fails because the session it would run on was lost with context of its own; it
    // opened no transaction, so the INSERT after it commits and another session sees it.
    $conn = onDatabase('PHP_FAILED_BEGIN_DB');
    $conn->execute('CREATE TABLE t (n INTEGER)');
    $conn->execute('USE SCHEMA PUBLIC');
    releaseBehindTheConnection((string) sessionOf($conn));
    assertSame(FrostlakeException::SESSION_LOST, thrownBy(fn () => $conn->beginTransaction())->getCode());
    $conn->execute('INSERT INTO t VALUES (1)');
    $other = Frostlake\connect(engineUrl() . '/PHP_FAILED_BEGIN_DB');
    assertSame([['N' => 1]], $other->execute('SELECT COUNT(*) AS n FROM t')->rows);
    $other->close();
    $conn->close();
});

// -- the testkit corpus -------------------------------------------------------

runTest('the testkit corpus replays through the driver', function () {
    $corpus = getenv('FL_CORPUS');
    if (!is_string($corpus) || $corpus === '') {
        throw new SkippedTest("set FL_CORPUS to frostlake's engine/src/test/resources/testkit to replay the testkit corpus");
    }
    if ((glob(rtrim($corpus, '/') . '/suites/*.json') ?: []) === []) {
        throw new \RuntimeException("FL_CORPUS=$corpus holds no suites/*.json");
    }
    $url = getenv('FROSTLAKE_URL');
    $classpath = getenv('FROSTLAKE_CLASSPATH');
    if ((!is_string($url) || $url === '') && (!is_string($classpath) || $classpath === '')) {
        throw new SkippedTest('no engine to run the corpus against (set FROSTLAKE_URL or FROSTLAKE_CLASSPATH)');
    }
    // testkit_runner.php runs in a process of its own: it sets the time zone and the float
    // precision for the whole process, and attaches to FROSTLAKE_URL or boots an engine itself.
    $runner = proc_open([PHP_BINARY, __DIR__ . '/../testkit_runner.php'], [STDIN, STDOUT, STDERR], $pipes);
    if ($runner === false) {
        throw new \RuntimeException('cannot start testkit_runner.php');
    }
    assertSame(0, proc_close($runner), 'testkit_runner.php exit status');
});

// -- summary ------------------------------------------------------------------

fwrite(STDOUT, "\n$passed passed, $failed failed"
    . ($skipped > 0 ? ", $skipped skipped" : '') . "\n");
exit($failed > 0 ? 1 : 0);
