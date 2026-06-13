<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    respond(['ok' => true]);
}

if ($path === '/intake/start' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sid = (string) ($_GET['sid'] ?? '');

    if ($sid === '') {
        respond(['error' => 'missing_sid'], 400);
    }

    append_session($sid, $_GET);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Mock telehealth intake</title><h1>Mock telehealth intake</h1><p>Session captured.</p>';
    exit;
}

if ($path === '/complete' && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    $sid = latest_sid();

    if ($sid === '') {
        respond(['error' => 'no_session'], 400);
    }

    respond([
        'ok' => true,
        'sid' => $sid,
    ]);
}

respond(['error' => 'not_found'], 404);

/**
 * @param array<string, mixed> $query
 */
function append_session(string $sid, array $query): void
{
    $log = (string) getenv('MOCK_TELEHEALTH_LOG');

    if ($log === '') {
        return;
    }

    file_put_contents($log, json_encode([
        'sid' => $sid,
        'query' => $query,
        'created_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function latest_sid(): string
{
    $log = (string) getenv('MOCK_TELEHEALTH_LOG');

    if ($log === '' || !is_file($log)) {
        return '';
    }

    $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $last = end($lines);

    if (!is_string($last)) {
        return '';
    }

    $decoded = json_decode($last, true);

    return is_array($decoded) ? (string) ($decoded['sid'] ?? '') : '';
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
