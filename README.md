# frostlake-php

A zero-dependency PHP driver for [Frostlake](https://frostlake.dev), speaking the
engine's HTTP protocol against a running `DatabaseHttpServer`. PHP ≥ 8.1, core only —
transport is stream contexts, parsing is `json_decode`. No extensions beyond the
defaults, no Composer packages.

## Engine version

Requires a Frostlake engine **0.0.7 or newer**. Ask a running server which one it is with
`SELECT CURRENT_VERSION()` — every release answers it, so the check works against any engine.

The driver versions independently of the engine: it speaks the HTTP protocol, not
the jar, so this is a floor rather than a lockstep pin.

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

`execute(string $sql, array $binds = [])` returns a `Frostlake\Result` with:

- `columns` — one entry per column: `name`, `dataType`, `precision`, `scale`, `nullable`.
- `rows` — associative arrays keyed by column name.
- `values` — the same cells positionally. A self-join reports `ID` twice and a hash
  cannot hold both, so this is the lossless view.
- `rowCount` — the affected-row count for DML, the row count for a query. `UPDATE` and
  `MERGE` report several counters and all of them are summed.

A failed statement throws `Frostlake\FrostlakeException` carrying the engine's error
message.

`executeAll(string $sql, array $binds = [])` returns **every** result set a statement
string produced, in order; `execute` hands back the first. Use it for multi-statement
scripts:

```php
[$first, $second] = $conn->executeAll("SELECT 1 AS a; SELECT 2 AS b;");
```

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
losing that offset to an `NTZ` cast; inserting one into a `TIMESTAMP_NTZ` or `DATE`
column keeps the wall-clock reading. A `?` inside a `$$…$$` body is left alone — UDF and
procedure bodies are written that way.

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

Without `FROSTLAKE_CLASSPATH` the integration tests skip themselves and only the
substitution unit tests run.

## Protocol

One `POST /api/execute` per statement with `{ sql, sessionId, autoCommit }`; the server
issues the `sessionId` on first contact and the driver echoes it back, so session state
(current database/schema, transactions) persists across statements. Failed statements
answer with a non-2xx status **and** the error JSON in the body — the driver sets
`ignore_errors => true` on the stream context so that body is readable. `GET
/api/health` backs `Frostlake\connect`'s reachability check.
