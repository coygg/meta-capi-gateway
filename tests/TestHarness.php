<?php

declare(strict_types=1);

final class TestHarness
{
    private int $failures = 0;
    private int $assertions = 0;

    public function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;

        if (!$condition) {
            $this->failures++;
            echo "FAIL: {$message}\n";
            return;
        }

        echo "ok: {$message}\n";
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue($expected === $actual, $message);

        if ($expected !== $actual) {
            echo '  expected: ' . var_export($expected, true) . "\n";
            echo '  actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function assertContains(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message);
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function assertions(): int
    {
        return $this->assertions;
    }
}

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($curl);

    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    $rawHeaders = substr((string) $raw, 0, (int) $headerSize);
    $responseBody = substr((string) $raw, (int) $headerSize);
    $parsedHeaders = [];

    foreach (preg_split('/\r\n|\n|\r/', trim($rawHeaders)) ?: [] as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $parsedHeaders[$name] ??= [];
        $parsedHeaders[$name][] = trim($value);
    }

    return [
        'status' => is_int($status) ? $status : 0,
        'headers' => $parsedHeaders,
        'body' => $responseBody,
    ];
}

function header_value(array $response, string $name): string
{
    $values = $response['headers'][strtolower($name)] ?? [];

    return $values[0] ?? '';
}

function cookie_header(array $response, string $existing = ''): string
{
    $cookies = [];

    if ($existing !== '') {
        foreach (explode(';', $existing) as $pair) {
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', trim($pair), 2);
                $cookies[$name] = $value;
            }
        }
    }

    foreach ($response['headers']['set-cookie'] ?? [] as $cookie) {
        $pair = explode(';', $cookie, 2)[0];
        if (str_contains($pair, '=')) {
            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = trim($value);
        }
    }

    $header = [];
    foreach ($cookies as $name => $value) {
        $header[] = $name . '=' . $value;
    }

    return implode('; ', $header);
}

function csrf_from_body(string $body): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $body, $match) === 1) {
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }

    return '';
}

/**
 * @return array<string, string>
 */
function query_params(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);

    return array_map(static fn (mixed $value): string => (string) $value, $params);
}

function wait_for_url(string $url, int $timeoutMs = 5000): void
{
    $started = microtime(true);

    do {
        try {
            $response = http_request('GET', $url);

            if ($response['status'] >= 200 && $response['status'] < 500) {
                return;
            }
        } catch (Throwable) {
        }

        usleep(100000);
    } while ((microtime(true) - $started) * 1000 < $timeoutMs);

    throw new RuntimeException('Timed out waiting for ' . $url);
}

/**
 * @param array<string, string> $env
 * @return resource
 */
function start_php_server(string $host, int $port, string $docroot, string $logFile, array $env = [], array $ini = []): mixed
{
    $args = [];

    foreach ($ini as $key => $value) {
        $args[] = '-d';
        $args[] = $key . '=' . $value;
    }

    $args[] = '-S';
    $args[] = $host . ':' . $port;
    $args[] = '-t';
    $args[] = $docroot;

    $command = quote_arg(PHP_BINARY);
    foreach ($args as $arg) {
        $command .= ' ' . quote_arg($arg);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, dirname($docroot), array_merge($_ENV, $env));

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP server on port ' . $port);
    }

    fclose($pipes[0]);

    return $process;
}

function stop_php_server(mixed $process): void
{
    if (is_resource($process)) {
        $status = proc_get_status($process);
        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;

        if ($pid > 0 && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL');
            return;
        }

        proc_terminate($process, 9);
    }
}

function quote_arg(string $value): string
{
    return '"' . str_replace('"', '\\"', $value) . '"';
}

function rm_rf(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        rm_rf($path . DIRECTORY_SEPARATOR . $entry);
    }

    @rmdir($path);
}
