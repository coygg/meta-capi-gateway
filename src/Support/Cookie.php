<?php

declare(strict_types=1);

namespace Gateway\Support;

final class Cookie
{
    public static function make(
        string $name,
        string $value,
        int $maxAge,
        bool $secure,
        string $sameSite = 'Lax',
    ): string {
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Max-Age=' . $maxAge,
            'Path=/',
            'HttpOnly',
            'SameSite=' . $sameSite,
        ];

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
