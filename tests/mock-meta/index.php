<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    respond(['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !str_ends_with($path, '/events')) {
    respond(['error' => 'not_found'], 404);
}

$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
$record = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'json' => is_array($decoded) ? $decoded : null,
    'raw' => $raw,
    'created_at' => gmdate('c'),
];
$log = (string) getenv('MOCK_META_LOG');

if ($log !== '') {
    file_put_contents($log, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

if (($_GET['access_token'] ?? '') === 'raw') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not-json';
    exit;
}

$eventCount = is_array($decoded['data'] ?? null) ? count($decoded['data']) : 0;
respond(['events_received' => $eventCount]);

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
