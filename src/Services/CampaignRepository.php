<?php

declare(strict_types=1);

namespace Gateway\Services;

use PDO;

final class CampaignRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $campaigns
     */
    public function seedFromConfig(array $campaigns): void
    {
        foreach ($campaigns as $slug => $campaign) {
            if (!is_string($slug) || $this->findBySlug($slug) !== null) {
                continue;
            }

            $campaign['slug'] = $slug;
            $this->save($campaign);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM campaigns ORDER BY slug ASC')->fetchAll();

        if (!is_array($rows)) {
            return []; // @coverage-ignore-line PDOStatement::fetchAll() returns an array for this query.
        }

        return array_map(fn (array $row): array => $this->rowToCampaign($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $slug): ?array
    {
        $campaign = $this->findBySlug($slug);

        if ($campaign === null || ($campaign['status'] ?? '') !== 'active') {
            return null;
        }

        return $campaign;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->rowToCampaign($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM campaigns WHERE slug = :slug LIMIT 1');
        $statement->execute([':slug' => $slug]);
        $row = $statement->fetch();

        return is_array($row) ? $this->rowToCampaign($row) : null;
    }

    /**
     * @param array<string, mixed> $campaign
     */
    public function save(array $campaign): int
    {
        $normalized = $this->normalize($campaign);
        $now = ClickRepository::now();
        $existing = $this->findBySlug($normalized['slug']);

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO campaigns (
                    slug, status, landing_url, form_url, public_fallback_url, event_source_url,
                    allowed_domains_json, required_params_json, accepted_utm_sources_json,
                    click_token_ttl_seconds, form_token_ttl_seconds, click_token_param, form_token_param,
                    capi_event_name, capi_custom_data_json, fallback_title, fallback_body,
                    intake_title, intake_body, created_at, updated_at
                ) VALUES (
                    :slug, :status, :landing_url, :form_url, :public_fallback_url, :event_source_url,
                    :allowed_domains_json, :required_params_json, :accepted_utm_sources_json,
                    :click_token_ttl_seconds, :form_token_ttl_seconds, :click_token_param, :form_token_param,
                    :capi_event_name, :capi_custom_data_json, :fallback_title, :fallback_body,
                    :intake_title, :intake_body, :created_at, :updated_at
                )
                SQL
            );
            $statement->execute($this->params($normalized, $now, $now));

            return (int) $this->pdo->lastInsertId();
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE campaigns SET
                status = :status,
                landing_url = :landing_url,
                form_url = :form_url,
                public_fallback_url = :public_fallback_url,
                event_source_url = :event_source_url,
                allowed_domains_json = :allowed_domains_json,
                required_params_json = :required_params_json,
                accepted_utm_sources_json = :accepted_utm_sources_json,
                click_token_ttl_seconds = :click_token_ttl_seconds,
                form_token_ttl_seconds = :form_token_ttl_seconds,
                click_token_param = :click_token_param,
                form_token_param = :form_token_param,
                capi_event_name = :capi_event_name,
                capi_custom_data_json = :capi_custom_data_json,
                fallback_title = :fallback_title,
                fallback_body = :fallback_body,
                intake_title = :intake_title,
                intake_body = :intake_body,
                updated_at = :updated_at
            WHERE slug = :slug
            SQL
        );
        $params = $this->params($normalized, (string) ($existing['created_at'] ?? $now), $now);
        unset($params[':created_at']);
        $statement->execute($params);

        return (int) $existing['id'];
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    public function normalize(array $campaign): array
    {
        $slug = strtolower(trim((string) ($campaign['slug'] ?? '')));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,80}$/', $slug)) {
            throw new \InvalidArgumentException('Slug must be 2-81 lowercase letters, numbers, dashes, or underscores.');
        }

        $status = (string) ($campaign['status'] ?? 'active');
        if (!in_array($status, ['active', 'paused'], true)) {
            $status = 'paused';
        }

        $requiredUrls = ['landing_url', 'form_url', 'public_fallback_url', 'event_source_url'];
        foreach ($requiredUrls as $key) {
            $value = trim((string) ($campaign[$key] ?? ''));
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException($key . ' must be an absolute URL.');
            }
            $campaign[$key] = $value;
        }

        return [
            'id' => isset($campaign['id']) ? (int) $campaign['id'] : null,
            'slug' => $slug,
            'status' => $status,
            'landing_url' => (string) $campaign['landing_url'],
            'form_url' => (string) $campaign['form_url'],
            'public_fallback_url' => (string) $campaign['public_fallback_url'],
            'event_source_url' => (string) $campaign['event_source_url'],
            'allowed_domains' => $this->stringList($campaign['allowed_domains'] ?? []),
            'required_params' => $this->stringList($campaign['required_params'] ?? ['ad_id', 'adset_id', 'campaign_id', 'utm_source']),
            'accepted_utm_sources' => $this->stringList($campaign['accepted_utm_sources'] ?? ['facebook', 'instagram']),
            'click_token_ttl_seconds' => max(60, (int) ($campaign['click_token_ttl_seconds'] ?? 1800)),
            'form_token_ttl_seconds' => max(60, (int) ($campaign['form_token_ttl_seconds'] ?? 7200)),
            'click_token_param' => trim((string) ($campaign['click_token_param'] ?? 'cid')) ?: 'cid',
            'form_token_param' => trim((string) ($campaign['form_token_param'] ?? 'sid')) ?: 'sid',
            'capi_event_name' => trim((string) ($campaign['capi_event_name'] ?? 'Lead')) ?: 'Lead',
            'capi_custom_data' => $this->customData($campaign['capi_custom_data'] ?? []),
            'fallback_title' => trim((string) ($campaign['fallback_title'] ?? 'Information')),
            'fallback_body' => trim((string) ($campaign['fallback_body'] ?? '')),
            'intake_title' => trim((string) ($campaign['intake_title'] ?? 'Online intake')),
            'intake_body' => trim((string) ($campaign['intake_body'] ?? '')),
            'created_at' => (string) ($campaign['created_at'] ?? ''),
            'updated_at' => (string) ($campaign['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToCampaign(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'landing_url' => (string) $row['landing_url'],
            'form_url' => (string) $row['form_url'],
            'public_fallback_url' => (string) $row['public_fallback_url'],
            'event_source_url' => (string) $row['event_source_url'],
            'allowed_domains' => $this->decodeList((string) $row['allowed_domains_json']),
            'required_params' => $this->decodeList((string) $row['required_params_json']),
            'accepted_utm_sources' => $this->decodeList((string) $row['accepted_utm_sources_json']),
            'click_token_ttl_seconds' => (int) $row['click_token_ttl_seconds'],
            'form_token_ttl_seconds' => (int) $row['form_token_ttl_seconds'],
            'click_token_param' => (string) $row['click_token_param'],
            'form_token_param' => (string) $row['form_token_param'],
            'capi_event_name' => (string) $row['capi_event_name'],
            'capi_custom_data' => $this->decodeAssoc((string) $row['capi_custom_data_json']),
            'fallback_title' => (string) $row['fallback_title'],
            'fallback_body' => (string) $row['fallback_body'],
            'intake_title' => (string) $row['intake_title'],
            'intake_body' => (string) $row['intake_body'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function params(array $campaign, string $createdAt, string $updatedAt): array
    {
        return [
            ':slug' => $campaign['slug'],
            ':status' => $campaign['status'],
            ':landing_url' => $campaign['landing_url'],
            ':form_url' => $campaign['form_url'],
            ':public_fallback_url' => $campaign['public_fallback_url'],
            ':event_source_url' => $campaign['event_source_url'],
            ':allowed_domains_json' => json_encode($campaign['allowed_domains'], JSON_UNESCAPED_SLASHES),
            ':required_params_json' => json_encode($campaign['required_params'], JSON_UNESCAPED_SLASHES),
            ':accepted_utm_sources_json' => json_encode($campaign['accepted_utm_sources'], JSON_UNESCAPED_SLASHES),
            ':click_token_ttl_seconds' => $campaign['click_token_ttl_seconds'],
            ':form_token_ttl_seconds' => $campaign['form_token_ttl_seconds'],
            ':click_token_param' => $campaign['click_token_param'],
            ':form_token_param' => $campaign['form_token_param'],
            ':capi_event_name' => $campaign['capi_event_name'],
            ':capi_custom_data_json' => json_encode($campaign['capi_custom_data'], JSON_UNESCAPED_SLASHES),
            ':fallback_title' => $campaign['fallback_title'],
            ':fallback_body' => $campaign['fallback_body'],
            ':intake_title' => $campaign['intake_title'],
            ':intake_body' => $campaign['intake_body'],
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n|,/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function customData(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Custom data must be valid JSON.');
            }
            return $decoded;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);

        return $this->stringList(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAssoc(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
