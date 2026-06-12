<?php

declare(strict_types=1);

namespace Gateway\Services;

final class TokenService
{
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $claims['iat'] = $now;
        $claims['exp'] = $now + $ttlSeconds;
        $claims['nonce'] = bin2hex(random_bytes(8));

        $payload = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES) ?: '{}');
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));

        return $payload . '.' . $signature;
    }

    /**
     * @return array{valid: bool, claims: array<string, mixed>, reason: string|null}
     */
    public function verify(string $token, ?string $expectedType = null): array
    {
        if (!str_contains($token, '.')) {
            return ['valid' => false, 'claims' => [], 'reason' => 'malformed_token'];
        }

        [$payload, $signature] = explode('.', $token, 2);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));

        if (!hash_equals($expected, $signature)) {
            return ['valid' => false, 'claims' => [], 'reason' => 'bad_signature'];
        }

        $json = self::base64UrlDecode($payload);
        $claims = json_decode($json, true);

        if (!is_array($claims)) {
            return ['valid' => false, 'claims' => [], 'reason' => 'bad_payload'];
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] < time()) {
            return ['valid' => false, 'claims' => $claims, 'reason' => 'expired_token'];
        }

        if ($expectedType !== null && ($claims['type'] ?? null) !== $expectedType) {
            return ['valid' => false, 'claims' => $claims, 'reason' => 'wrong_token_type'];
        }

        return ['valid' => true, 'claims' => $claims, 'reason' => null];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
