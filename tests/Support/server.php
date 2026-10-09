<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server, used by HttpServerTest to exercise the real
 * Guzzle and cURL transport. It echoes what it received so tests can check the wire format.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

$received = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'path' => $path,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'idempotencyKey' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
    'body' => json_decode((string) file_get_contents('php://input'), true),
];

header('Content-Type: application/json');
header('X-Request-Id: req_server');

if (str_starts_with($path, '/slow/')) {
    usleep(800_000);
}

if (str_starts_with($path, '/redirect/')) {
    header('Location: http://127.0.0.1:1/stolen', true, 307);

    return;
}

if (str_ends_with($path, '/v1/emails/send')) {
    http_response_code(202);
    echo json_encode([
        'message' => 'Email queued',
        'data' => [
            'messageId' => 'msg_1',
            'deliveryIds' => ['del_1'],
            'totalQueued' => 1,
            'scheduledAt' => null,
            'received' => $received,
        ],
    ]);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found', 'message' => 'Route not found', 'received' => $received]);
