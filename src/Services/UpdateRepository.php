<?php

declare(strict_types=1);

namespace Gateway\Services;

use PDO;

final class UpdateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function settings(): array
    {
        return [
            'repo_url' => $this->get('update_repo_url', 'https://github.com/coygg/meta-capi-gateway'),
            'branch' => $this->get('update_branch', 'main'),
            'deploy_hook_url' => $this->get('update_deploy_hook_url', ''),
            'latest_commit_sha' => $this->get('update_latest_commit_sha', ''),
            'latest_commit_url' => $this->get('update_latest_commit_url', ''),
            'latest_checked_at' => $this->get('update_latest_checked_at', ''),
            'last_deploy_triggered_at' => $this->get('update_last_deploy_triggered_at', ''),
            'last_deploy_status' => $this->get('update_last_deploy_status', ''),
        ];
    }

    public function saveSettings(string $repoUrl, string $branch, string $deployHookUrl): void
    {
        $repoUrl = $this->normalizeRepoUrl($repoUrl);
        $branch = $this->normalizeBranch($branch);
        $deployHookUrl = $this->normalizeDeployHookUrl($deployHookUrl);

        $this->setMany([
            'update_repo_url' => $repoUrl,
            'update_branch' => $branch,
            'update_deploy_hook_url' => $deployHookUrl,
        ]);
    }

    public function markLatestCommit(string $sha, string $url): void
    {
        $this->setMany([
            'update_latest_commit_sha' => $sha,
            'update_latest_commit_url' => $url,
            'update_latest_checked_at' => ClickRepository::now(),
        ]);
    }

    public function markDeployTriggered(int $status): void
    {
        $this->setMany([
            'update_last_deploy_triggered_at' => ClickRepository::now(),
            'update_last_deploy_status' => 'HTTP ' . $status,
        ]);
    }

    public function markDeployFailed(string $message): void
    {
        $this->setMany([
            'update_last_deploy_triggered_at' => ClickRepository::now(),
            'update_last_deploy_status' => substr($message, 0, 240),
        ]);
    }

    private function get(string $key, string $default = ''): string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, string> $settings
     */
    private function setMany(array $settings): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at'
        );
        $now = ClickRepository::now();

        foreach ($settings as $key => $value) {
            $statement->execute([
                ':key' => $key,
                ':value' => $value,
                ':updated_at' => $now,
            ]);
        }
    }

    private function normalizeRepoUrl(string $repoUrl): string
    {
        $repoUrl = trim($repoUrl);

        if ($repoUrl === '') {
            throw new \InvalidArgumentException('GitHub repository URL is required.');
        }

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repoUrl) === 1) {
            return 'https://github.com/' . $repoUrl;
        }

        $parts = parse_url($repoUrl);

        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'github.com') {
            throw new \InvalidArgumentException('Use a GitHub HTTPS repository URL, such as https://github.com/coygg/meta-capi-gateway.');
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);

        if (count($segments) < 2 || $segments[0] === '' || $segments[1] === '') {
            throw new \InvalidArgumentException('GitHub repository URL must include an owner and repository name.');
        }

        return 'https://github.com/' . $segments[0] . '/' . preg_replace('/\.git$/', '', $segments[1]);
    }

    private function normalizeBranch(string $branch): string
    {
        $branch = trim($branch);

        if ($branch === '') {
            throw new \InvalidArgumentException('Update branch is required.');
        }

        if (preg_match('#^[A-Za-z0-9._/\-]+$#', $branch) !== 1 || str_contains($branch, '..')) {
            throw new \InvalidArgumentException('Update branch contains unsupported characters.');
        }

        return $branch;
    }

    private function normalizeDeployHookUrl(string $deployHookUrl): string
    {
        $deployHookUrl = trim($deployHookUrl);

        if ($deployHookUrl === '') {
            return '';
        }

        $parts = parse_url($deployHookUrl);

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \InvalidArgumentException('Deploy hook URL must be an HTTPS URL.');
        }

        return $deployHookUrl;
    }
}
