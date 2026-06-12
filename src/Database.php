<?php

declare(strict_types=1);

namespace Gateway;

use PDO;

final class Database
{
    private function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromConfig(string $root, Config $config): self
    {
        $path = $config->dbPath($root);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS clicks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                click_id TEXT NOT NULL UNIQUE,
                campaign_slug TEXT NOT NULL,
                decision TEXT NOT NULL,
                fallback_reason TEXT,
                landing_url TEXT,
                ad_id TEXT,
                adset_id TEXT,
                meta_campaign_id TEXT,
                fbclid TEXT,
                fbc TEXT,
                fbp TEXT,
                utm_source TEXT,
                utm_medium TEXT,
                utm_campaign TEXT,
                utm_content TEXT,
                client_ip TEXT,
                client_ip_hash TEXT,
                client_user_agent TEXT,
                user_agent_hash TEXT,
                query_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT
            );

            CREATE TABLE IF NOT EXISTS form_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL UNIQUE,
                click_id TEXT NOT NULL,
                campaign_slug TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                FOREIGN KEY(click_id) REFERENCES clicks(click_id)
            );

            CREATE TABLE IF NOT EXISTS conversions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                click_id TEXT NOT NULL,
                form_session_id TEXT,
                campaign_slug TEXT NOT NULL,
                event_name TEXT NOT NULL,
                dry_run INTEGER NOT NULL DEFAULT 1,
                meta_status_code INTEGER,
                meta_response_json TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY(click_id) REFERENCES clicks(click_id)
            );

            CREATE TABLE IF NOT EXISTS rate_limits (
                rate_key TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hostname TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT 'pending',
                cname_target TEXT NOT NULL,
                last_error TEXT,
                verified_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL,
                landing_url TEXT NOT NULL,
                form_url TEXT NOT NULL,
                public_fallback_url TEXT NOT NULL,
                event_source_url TEXT NOT NULL,
                allowed_domains_json TEXT NOT NULL,
                required_params_json TEXT NOT NULL,
                accepted_utm_sources_json TEXT NOT NULL,
                click_token_ttl_seconds INTEGER NOT NULL,
                form_token_ttl_seconds INTEGER NOT NULL,
                click_token_param TEXT NOT NULL,
                form_token_param TEXT NOT NULL,
                capi_event_name TEXT NOT NULL,
                capi_custom_data_json TEXT NOT NULL,
                fallback_title TEXT NOT NULL,
                fallback_body TEXT NOT NULL,
                intake_title TEXT NOT NULL,
                intake_body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS clicks_campaign_created_idx ON clicks(campaign_slug, created_at);
            CREATE INDEX IF NOT EXISTS form_sessions_click_idx ON form_sessions(click_id);
            CREATE INDEX IF NOT EXISTS conversions_click_idx ON conversions(click_id);
            CREATE INDEX IF NOT EXISTS domains_status_idx ON domains(status);
            CREATE INDEX IF NOT EXISTS campaigns_status_idx ON campaigns(status);
            SQL
        );
    }
}
