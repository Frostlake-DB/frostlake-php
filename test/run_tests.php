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
    } catch (SkippedTest) {
        $skipped++;
        fwrite(STDOUT, "skip $name\n");
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
    $conn->execute('CREATE TABLE stamps (id INTEGER, moment TIMESTAMP_NTZ, d DATE)');
    $moment = new \DateTimeImmutable('2026-08-13 12:34:56.789000');
    $conn->execute('INSERT INTO stamps VALUES (?, ?, ?)', [1, $moment, new \DateTimeImmutable('2026-08-13')]);
    $row = $conn->execute('SELECT moment, d FROM stamps WHERE id = 1')->rows[0];
    if (!$row['MOMENT'] instanceof \DateTimeImmutable) {
        throw new \RuntimeException('MOMENT is not DateTimeImmutable');
    }
    assertSame('2026-08-13 12:34:56.789000', $row['MOMENT']->format('Y-m-d H:i:s.u'));
    assertSame('2026-08-13', $row['D']->format('Y-m-d'));
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
    $results = $conn->executeAll('SELECT ? AS a; SELECT ? AS b;', [1, 2]);
    assertSame(2, count($results), 'result set count');
    assertSame([['A' => 1]], $results[0]->rows);
    assertSame([['B' => 2]], $results[1]->rows);
    // execute() keeps handing back the first one.
    assertSame([['A' => 1]], $conn->execute('SELECT 1 AS a; SELECT 2 AS b;')->rows);
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

// -- summary ------------------------------------------------------------------

fwrite(STDOUT, "\n$passed passed, $failed failed"
    . ($skipped > 0 ? ", $skipped skipped (FROSTLAKE_CLASSPATH not set)" : '') . "\n");
exit($failed > 0 ? 1 : 0);
