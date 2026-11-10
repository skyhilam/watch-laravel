<?php

declare(strict_types=1);

// Router for PHP's built-in server, standing in for the central ingest
// endpoint in StreamCentralClientTest. It appends what it received to the file
// named by WATCH_TEST_RECORD and answers by path.

$record = getenv('WATCH_TEST_RECORD');
if (is_string($record) && $record !== '') {
    file_put_contents($record, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null),
        'body' => file_get_contents('php://input'),
    ])."\n", FILE_APPEND | LOCK_EX);
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

if ($path === '/busy') {
    http_response_code(503);
    echo 'maintenance';

    return true;
}

if ($path === '/moved') {
    http_response_code(302);
    header('Location: /api/v1/ingest');

    return true;
}

if ($path === '/bad') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":"unknown reason"}';

    return true;
}

header('Content-Type: application/json');
echo '{"accepted":2,"duplicates":0,"dropped_quota":0}';

return true;
