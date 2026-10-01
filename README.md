# frostlake-php

A zero-dependency PHP driver for [Frostlake](https://frostlake.dev), speaking the
engine's HTTP protocol against a running `DatabaseHttpServer`. PHP ≥ 8.1, core only —
transport is stream contexts, parsing is `json_decode`. No extensions beyond the
defaults, no Composer packages.

## Engine version

Requires a Frostlake engine **0.2.0 or newer**. Ask a running server which one it is with
`SELECT CURRENT_VERSION()` — every release answers it, so the check works against any engine.

The driver versions independently of the engine: it speaks the HTTP protocol, not
the jar, so this is a floor rather than a lockstep pin. Recovering from a lost session and
releasing the session on close need engine **0.1.0**; see [Session lifetime](#session-lifetime).

## Usage

```php
require "src/Frostlake.php";   // or composer's autoloader

$conn = Frostlake\connect("frostlake://localhost:18082/MY_DB?schema=PUBLIC");

$conn->execute("CREATE TABLE people (id INTEGER, name VARCHAR)");
$result = $conn->execute("INSERT INTO people VALUES (?, ?), (?, ?)", [1, "Ada", 2, "Grace"]);
$result->rowCount; // 2

$result = $conn->execute("SELECT id, name FROM people WHERE id = ?", [1]);
$result->rows; // [["ID" => 1, "NAME" => "Ada"]]

$conn->close();
```

The database and schema named in the DSN go onto the session before its first statement. A
name that is a valid unquoted identifier means what it means unquoted in SQL, so it is folded
to upper case: `frostlake://localhost:18082/my_db?schema=public` selects `MY_DB` and `PUBLIC`.
A name written in double quotes (`/%22my%20Db%22`, or `schema=%22Mixed%22` in the query) is
used as it stands, for an exact-case match, and anything else — a leading digit, a space, a
hyphen — is quoted as given.

`execute(string $sql, array $binds = [])` returns a `Frostlake\Result` with:

- `columns` — one entry per column: `name`, `dataType`, `precision`, `scale`, `length`,
  `nullable`. `length` is the declared width of a text (characters) or binary (bytes)
  column — the account reports it as both the precision and the display size — and `null`
  for every other type, and for a server that predates the field.
- `rows` — associative arrays keyed by column name.
- `values` — the same cells positionally. A self-join reports `ID` twice and a hash
  cannot hold both, so this is the lossless view.
- `rowCount` — the affected-row count for DML, the row count for a query. `UPDATE` and
  `MERGE` report several counters and all of them are summed.

A failed statement throws `Frostlake\FrostlakeException` carrying the engine's error
message. A session the engine no longer holds, when it held what a fresh one cannot
reproduce, throws one whose code is `FrostlakeException::SESSION_LOST` (see
[Session lifetime](#session-lifetime)).

`executeAll(string $sql, array $binds = [])` returns **every** result set a statement
string produced, in order; `execute` hands back the first. Use it for multi-statement
scripts — asking the session for them first, since a request carries one statement unless
it says otherwise, exactly as on the account:

```php
$conn->execute('ALTER SESSION SET MULTI_STATEMENT_COUNT = 0');
[$first, $second] = $conn->executeAll("SELECT 1 AS a; SELECT 2 AS b;");
```

A call can declare its own count instead, with the optional third parameter of
`execute` and `executeAll`:

```php
[$first, $second] = $conn->executeAll("SELECT 1 AS a; SELECT 2 AS b;", [], 2);
```

`$multiStatementCount` says how many statements that one call carries, `0` for any
number. It travels with that request and outranks the session's
`MULTI_STATEMENT_COUNT` for it, but changes no session state — nothing to save and
put back, and other statements on the same connection are unaffected. Left out (or
`null`), nothing is sent and the session's value decides, which is 1 until it is told
otherwise.

### Transactions

```php
$conn->transaction(function ($c) {
    $c->execute("INSERT INTO acc VALUES (2)");
});                            // commits; rolls back if the closure throws

$conn->beginTransaction();     // or drive it by hand
$conn->execute("...");
$conn->commit();               // / $conn->rollback()
```

`isAutoCommit()` reports whether a transaction is open. A `commit()` or `rollback()`
that fails still returns the connection to autocommit, so a lost ending cannot leave
later statements running inside a transaction nobody closes.

`beginTransaction()` sends `BEGIN` with autocommit off, and the connection leaves
autocommit only once the engine has opened the transaction. A begin that fails — refused,
unanswered or unreadable, lost with its session, or never sent because a `USE` queued
ahead of it was refused — leaves the connection in autocommit, so the statements after it
commit as they run. `transaction()` follows a begin that fails with a best-effort
`rollback()` as well, in case a `BEGIN` whose answer was lost did open a transaction.

### Session lifetime

A connection is one session on the engine, and the session is where the current
database and schema, session variables, `ALTER SESSION` settings, temporary tables and
an open transaction live. The engine ends a session after 30 minutes idle, when it is
released, or when the server restarts. From engine 0.1.0 on every answer says whether
the session it ran in is new (`newSession`), and the driver takes the first answer that
names a session as the sign of which kind of engine it is talking to.

**What is sent.** Every request after the first names the session. To an engine that
reports `newSession` it also sends `requireSession: true`, so a session the engine no
longer holds is refused (HTTP 404) instead of being quietly replaced by a fresh one in
which the statement would run somewhere else. An older engine is sent neither that
field nor the release below.

**After a lost session.** The refused statement did not run. If the lost session held
nothing a fresh one lacks, the driver starts a fresh session, puts the DSN's database and
schema back on it, and sends the statement once more; if that is refused too, it throws.
If the lost session held an open transaction, or context set up with `USE`,
`SET`/`UNSET`, `ALTER SESSION`, a temporary object or a `CREATE`/`DROP` of a database or
schema, running the statement again could put it somewhere its author did not intend, so
the driver throws a `FrostlakeException` whose code is `FrostlakeException::SESSION_LOST`
instead, saying which. Either way the connection stays usable: its next statement starts
a fresh session on the DSN's database and schema, in autocommit.

```php
try {
    $conn->execute("INSERT INTO acc VALUES (2)");
} catch (Frostlake\FrostlakeException $e) {
    if ($e->getCode() !== Frostlake\FrostlakeException::SESSION_LOST) {
        throw $e;
    }
    // The transaction and anything set up on the session are gone; start the unit of
    // work over. The connection itself is fine.
}
```

**Close.** `close()` sends `DELETE /api/sessions/{id}`, which ends the session and rolls
back a transaction it left open. It is best effort, bounded to five seconds, and never
throws. Closing again sends nothing. An engine older than 0.1.0 has no such endpoint, so
it is not asked, and the session lingers until its idle expiry — as does the session of
a connection that is never closed.

### Bind values

Parameters are inlined client-side (`?` placeholders); placeholders inside string
literals, quoted identifiers and comments are left alone.

| PHP value | SQL literal |
| --- | --- |
| `null` | `NULL` |
| `bool` | `TRUE` / `FALSE` |
| `int` | as written |
| `float` | shortest text that reads back as the same float (`1.0`, `0.30000000000000004`) |
| `string` | `'…'` (backslashes and quotes escaped) |
| `Frostlake\Binary` | `X'hex'` |
| `DateTimeInterface` | `'…±HH:MM'::TIMESTAMP_TZ` |
| list array | `[…]` (elements formatted recursively) |

A PHP date-time always carries a UTC offset, so it binds as `TIMESTAMP_TZ` rather than
losing that offset to an `NTZ` cast. A `TIMESTAMP_TZ` column takes it as it is, and a
`DATE` column keeps its wall-clock date. A `TIMESTAMP_NTZ` or `TIMESTAMP_LTZ` column
refuses it while compiling, as the account refuses any `TIMESTAMP_TZ` written into one
(`expecting TIMESTAMP_NTZ(9) but got TIMESTAMP_TZ(9)`), so cast the bind there:
`CAST(? AS TIMESTAMP_NTZ)` keeps the wall-clock reading and `CAST(? AS TIMESTAMP_LTZ)`
the instant. A `?` inside a `$$…$$` body is left alone — UDF and procedure bodies are
written that way.

Array binds need `INSERT … SELECT ?` rather than `INSERT … VALUES (?)`: the engine's
`VALUES` clause takes literals and casts but not an array constructor.

### Result types

`NUMBER`/`FLOAT`/`BOOLEAN` cells arrive as PHP numerics and booleans straight from
JSON; integers beyond `PHP_INT_MAX` stay **exact digit strings**
(`JSON_BIGINT_AS_STRING`) instead of degrading to floats. `DATE` and `TIMESTAMP*`
become `DateTimeImmutable`, `BINARY` raw bytes; `TIME` and semi-structured values keep
their wire shape.

Fixed-point columns are the one lossy case: a `NUMBER(38,10)` cell decodes to a PHP
float, so digits past a double's ~17 significant ones are lost. PHP has no built-in
arbitrary-precision decimal to decode into; read `columns[i]['scale']` and re-read the
value as a string via `TO_VARCHAR(...)` when the exact digits matter.

## Running the tests

The self-contained runner (no PHPUnit) boots a real server from the engine's compiled
classes:

```sh
export JAVA_HOME=/path/to/jdk17
export FROSTLAKE_CLASSPATH="/path/to/frostlake/engine/target/classes:<engine deps>"
php test/run_tests.php
```

Without `FROSTLAKE_CLASSPATH` the integration tests skip themselves and only the unit
tests run — among them the session tests, whose engine is `test/scripted_engine.php`
under PHP's built-in web server, answering every request from a script.

## Testkit corpus runner

`testkit_runner.php` replays the engine's language-neutral SQL corpus (the JSON suites in
`frostlake/engine/src/test/resources/testkit/suites`, format in the `SCHEMA.md` beside
them) through this driver. Each case runs on a connection of its own, and the report has
one row per case. `FL_CORPUS` names that testkit directory, best as an absolute path; with
it set, `php test/run_tests.php` runs the replay as one more test, which fails when any
case does, and without it, or with no engine to replay against, that test is skipped:

```sh
FL_CORPUS=/path/to/frostlake/engine/src/test/resources/testkit php test/run_tests.php
```

It also runs on its own:

```sh
export FL_CORPUS=/path/to/frostlake/engine/src/test/resources/testkit
FROSTLAKE_URL=frostlake://127.0.0.1:18082 php testkit_runner.php    # attach to a running server
FROSTLAKE_CLASSPATH="<engine classes + deps>" php testkit_runner.php  # or boot one
```

- `FL_CORPUS`: the testkit directory, whose `suites/*.json` are replayed. Without it the
  runner replays nothing and says so.
- `FROSTLAKE_TESTKIT_REPORT`: the TSV report (`suite`, `test`, `status`, `failedStep`,
  `detail`, `ms`). Defaults to `results/testkit-php.tsv`. `missing-apis-php.md` is written
  beside it, listing the checks this transport cannot express. The protocol reports a
  refusal as a message only, so an expected error code or SQLSTATE is listed there instead
  of being checked.
- `FROSTLAKE_TESTKIT_FILTER`: replay only the suites whose name contains this text.

A case is skipped when its `skip` clause names `php` or `http`. The runner ends with
`testkit [php]: <P> passed, <F> failed, <S> skipped` and exits 1 if any case failed or
errored, or if `FL_CORPUS` holds no suites.

## Protocol

One `POST /api/execute` per statement with `{ sql, sessionId, autoCommit }` — plus
`multiStatementCount` when a call declares one, and nothing at all when it does not; the server
issues the `sessionId` on first contact and the driver echoes it back, so session state
(current database/schema, transactions) persists across statements. To an engine that
reports `newSession` the driver adds `requireSession: true`, and `close()` sends
`DELETE /api/sessions/{id}` (see [Session lifetime](#session-lifetime)). Failed statements
answer with a non-2xx status **and** the error JSON in the body — the driver sets
`ignore_errors => true` on the stream context so that body is readable. `GET
/api/health` backs `Frostlake\connect`'s reachability check.
