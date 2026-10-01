<?php

/**
 * A stand-in engine for the session tests, served by PHP's built-in web server:
 *
 *   FROSTLAKE_SCRIPT=/some/dir php -S 127.0.0.1:PORT test/scripted_engine.php
 *
 * Every request is answered with the next step of $FROSTLAKE_SCRIPT/script.json
 * — {"status": …, "body": …} — and recorded as one line of sent.jsonl beside
 * it, so a test decides every answer and sees every request. A request the
 * script did not expect is answered 599, which the driver cannot mistake for
 * one of the engine's answers.
 */

declare(strict_types=1);

$dir = (string) getenv('FROSTLAKE_SCRIPT');
$body = (string) file_get_contents('php://input');
$sent = [
    'verb' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'payload' => $body === '' ? null : json_decode($body, true),
];
file_put_contents("$dir/sent.jsonl", json_encode($sent) . "\n", FILE_APPEND);

$script = json_decode((string) file_get_contents("$dir/script.json"), true);
$step = is_array($script) ? array_shift($script) : null;
file_put_contents("$dir/script.json", json_encode($script ?? []));

header('Content-Type: application/json');
if (!is_array($step)) {
    http_response_code(599);
    echo '{"unscripted":true}';
    return;
}
http_response_code((int) $step['status']);
echo $step['body'];
