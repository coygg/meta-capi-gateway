<?php

declare(strict_types=1);

namespace Gateway\Support;

final class Url
{
    /**
     * @param array<string, string> $params
     */
    public static function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid absolute URL: ' . $url);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }

        $rebuilt = $parts['scheme'] . '://';

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }

        $rebuilt .= $parts['host'];

        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param list<string> $allowedDomains
     */
    public static function assertAllowed(string $url, array $allowedDomains): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('URL is missing a host: ' . $url);
        }

        $host = strtolower($host);

        foreach ($allowedDomains as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if ($host === $allowed) {
                return;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                return;
            }
        }

        throw new \RuntimeException('Blocked redirect to non-allowlisted host: ' . $host);
    }
}
