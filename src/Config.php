<?php

declare(strict_types=1);

namespace Gateway;

final class Config
{
    /**
     * @param array<string, array<string, mixed>> $campaigns
     * @param array<string, mixed> $app
     */
    private function __construct(
        private readonly array $campaigns,
        private readonly array $app,
    ) {
    }

    public static function load(string $root): self
    {
        $campaignConfigPath = Env::get('CAMPAIGN_CONFIG_PATH', $root . '/config/campaigns.php');
        $baseUrl = Env::get('APP_BASE_URL', '');

        if (!is_string($baseUrl) || $baseUrl === '') {
            $baseUrl = Env::get('RENDER_EXTERNAL_URL', 'http://127.0.0.1:8080');
        }

        if (!is_string($campaignConfigPath) || $campaignConfigPath === '') {
            $campaignConfigPath = $root . '/config/campaigns.php';
        }

        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $campaignConfigPath) && !str_starts_with($campaignConfigPath, '/')) {
            $campaignConfigPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $campaignConfigPath);
        }

        $campaigns = require $campaignConfigPath;

        return new self(
            campaigns: $campaigns,
            app: [
                'env' => Env::get('APP_ENV', 'production'),
                'base_url' => rtrim((string) $baseUrl, '/'),
                'secret' => (string) Env::get('APP_SECRET', ''),
                'db_path' => (string) Env::get('DB_PATH', 'storage/gateway.sqlite'),
                'cname_target' => Env::get('GATEWAY_CNAME_TARGET', ''),
                'cookie_secure' => Env::bool('COOKIE_SECURE', true),
                'trust_proxy' => Env::bool('TRUST_PROXY', false),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function campaign(string $slug): ?array
    {
        $campaign = $this->campaigns[$slug] ?? null;

        if (!is_array($campaign)) {
            return null;
        }

        return $campaign;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function campaigns(): array
    {
        return $this->campaigns;
    }

    /**
     * @return array<string, mixed>
     */
    public function app(): array
    {
        return $this->app;
    }

    public function appSecret(): string
    {
        $secret = (string) ($this->app['secret'] ?? '');

        if (strlen($secret) < 32) {
            throw new \RuntimeException('APP_SECRET must be at least 32 characters.');
        }

        return $secret;
    }

    public function dbPath(string $root): string
    {
        $path = (string) ($this->app['db_path'] ?? 'storage/gateway.sqlite');

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->app[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->app[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
