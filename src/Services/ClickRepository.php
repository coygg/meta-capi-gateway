<?php

declare(strict_types=1);

namespace Gateway\Services;

use PDO;

final class ClickRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function recordClick(array $data): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO clicks (
                click_id, campaign_slug, decision, fallback_reason, landing_url,
                ad_id, adset_id, meta_campaign_id, fbclid,
                utm_source, utm_medium, utm_campaign, utm_content,
                client_ip, client_ip_hash, client_user_agent, user_agent_hash,
                query_json, created_at, expires_at
            ) VALUES (
                :click_id, :campaign_slug, :decision, :fallback_reason, :landing_url,
                :ad_id, :adset_id, :meta_campaign_id, :fbclid,
                :utm_source, :utm_medium, :utm_campaign, :utm_content,
                :client_ip, :client_ip_hash, :client_user_agent, :user_agent_hash,
                :query_json, :created_at, :expires_at
            )
            SQL
        );

        $statement->execute([
            ':click_id' => $data['click_id'],
            ':campaign_slug' => $data['campaign_slug'],
            ':decision' => $data['decision'],
            ':fallback_reason' => $data['fallback_reason'] ?? null,
            ':landing_url' => $data['landing_url'] ?? null,
            ':ad_id' => $data['ad_id'] ?? null,
            ':adset_id' => $data['adset_id'] ?? null,
            ':meta_campaign_id' => $data['meta_campaign_id'] ?? null,
            ':fbclid' => $data['fbclid'] ?? null,
            ':utm_source' => $data['utm_source'] ?? null,
            ':utm_medium' => $data['utm_medium'] ?? null,
            ':utm_campaign' => $data['utm_campaign'] ?? null,
            ':utm_content' => $data['utm_content'] ?? null,
            ':client_ip' => $data['client_ip'] ?? null,
            ':client_ip_hash' => $data['client_ip_hash'] ?? null,
            ':client_user_agent' => $data['client_user_agent'] ?? null,
            ':user_agent_hash' => $data['user_agent_hash'] ?? null,
            ':query_json' => json_encode($data['query'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}',
            ':created_at' => $data['created_at'],
            ':expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClick(string $clickId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM clicks WHERE click_id = :click_id LIMIT 1');
        $statement->execute([':click_id' => $clickId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function createFormSession(string $sessionId, string $clickId, string $campaignSlug, string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO form_sessions (session_id, click_id, campaign_slug, created_at, expires_at) VALUES (:session_id, :click_id, :campaign_slug, :created_at, :expires_at)'
        );

        $statement->execute([
            ':session_id' => $sessionId,
            ':click_id' => $clickId,
            ':campaign_slug' => $campaignSlug,
            ':created_at' => self::now(),
            ':expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFormSession(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM form_sessions WHERE session_id = :session_id LIMIT 1');
        $statement->execute([':session_id' => $sessionId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public static function now(): string
    {
        return gmdate('c');
    }
}
