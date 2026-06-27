<?php

declare(strict_types=1);

namespace Gateway\Services;

final class UpdateService
{
    /**
     * @var callable(string, string, array<string, string>, ?string): array{status: int, body: string}
     */
    private $httpClient;

    /**
     * @param callable(string, string, array<string, string>, ?string): array{status: int, body: string}|null $httpClient
     */
    public function __construct(?callable $httpClient = null)
    {
        $this->httpClient = $httpClient ?? $this->curlRequest(...);
    }

    /**
     * @return array{sha: string, short_sha: string, url: string}
     */
    public function latestCommit(string $repoUrl, string $branch): array
    {
        [$owner, $repo] = $this->githubOwnerRepo($repoUrl);
        $apiUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits/' . rawurlencode($branch);
        $response = $this->request('GET', $apiUrl, [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Meta-Attribution-Gateway',
        ]);

        if ($response['status'] !== 200) {
            throw new \RuntimeException('GitHub update check failed with HTTP ' . $response['status'] . '.');
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded) || !is_string($decoded['sha'] ?? null) || !is_string($decoded['html_url'] ?? null)) {
            throw new \RuntimeException('GitHub update check returned an unexpected response.');
        }

        $sha = $decoded['sha'];

        return [
            'sha' => $sha,
            'short_sha' => substr($sha, 0, 7),
            'url' => $decoded['html_url'],
        ];
    }

    /**
     * @return array{status: int, body: string}
     */
    public function triggerDeploy(string $deployHookUrl): array
    {
        $deployHookUrl = trim($deployHookUrl);

        if ($deployHookUrl === '') {
            throw new \RuntimeException('Save a deploy hook URL before starting an update.');
        }

        $parts = parse_url($deployHookUrl);

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('Deploy hook URL must be an HTTPS URL.');
        }

        $response = $this->request('POST', $deployHookUrl, [
            'User-Agent' => 'Meta-Attribution-Gateway',
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Deploy hook failed with HTTP ' . $response['status'] . '.');
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function githubOwnerRepo(string $repoUrl): array
    {
        $repoUrl = trim($repoUrl);

        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $repoUrl, $match) === 1) {
            return [$match[1], preg_replace('/\.git$/', '', $match[2])];
        }

        $parts = parse_url($repoUrl);

        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'github.com') {
            throw new \RuntimeException('Update checks require a GitHub HTTPS repository URL.');
        }

        $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));

        if (count($segments) < 2 || $segments[0] === '' || $segments[1] === '') {
            throw new \RuntimeException('GitHub repository URL must include an owner and repository name.');
        }

        return [$segments[0], preg_replace('/\.git$/', '', $segments[1])];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return ($this->httpClient)($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     *
     * @coverage-ignore-start
     */
    private function curlRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize update request.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);

        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Update request failed: ' . $error);
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => is_int($status) ? $status : 0,
            'body' => (string) $raw,
        ];
    }
    // @coverage-ignore-end
}
