<?php

declare(strict_types=1);

namespace Gateway\Support;

final class Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            body: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            status: $status,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self(
            body: $body,
            status: $status,
            headers: [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        return new self(
            body: '',
            status: $status,
            headers: array_merge([
                'Location' => $url,
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
            ], $headers),
        );
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $values) {
            foreach ((array) $values as $value) {
                header($name . ': ' . $value, false);
            }
        }

        echo $this->body;
    }
}
