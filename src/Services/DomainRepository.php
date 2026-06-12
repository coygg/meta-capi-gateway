<?php

declare(strict_types=1);

namespace Gateway\Services;

use Gateway\Config;
use PDO;

final class DomainRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly mixed $dnsResolver = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM domains ORDER BY hostname ASC')->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<string>
     */
    public function activeHostnames(): array
    {
        $statement = $this->pdo->query("SELECT hostname FROM domains WHERE status = 'active' ORDER BY hostname ASC");
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('strval', is_array($rows) ? $rows : [])));
    }

    public function add(string $input): void
    {
        $hostname = self::normalizeHostname($input);
        $target = $this->cnameTarget();
        $now = ClickRepository::now();

        $statement = $this->pdo->prepare(
            'INSERT INTO domains (hostname, status, cname_target, created_at, updated_at) VALUES (:hostname, :status, :cname_target, :created_at, :updated_at)
             ON CONFLICT(hostname) DO UPDATE SET cname_target = excluded.cname_target, updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':hostname' => $hostname,
            ':status' => $hostname === $target || in_array($hostname, ['localhost', '127.0.0.1'], true) ? 'active' : 'pending',
            ':cname_target' => $target,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM domains WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    public function verify(int $id): void
    {
        $domain = $this->find($id);

        if ($domain === null) {
            throw new \RuntimeException('Domain not found.');
        }

        $hostname = (string) $domain['hostname'];
        $target = (string) $domain['cname_target'];
        $status = 'pending';
        $error = null;
        $verifiedAt = null;

        if ($hostname === $target || in_array($hostname, ['localhost', '127.0.0.1'], true)) {
            $status = 'active';
            $verifiedAt = ClickRepository::now();
        } else {
            $records = $this->resolveCnameRecords($hostname);
            $matches = false;

            if (is_array($records)) {
                foreach ($records as $record) {
                    $cname = rtrim(strtolower((string) ($record['target'] ?? '')), '.');
                    if ($cname === rtrim(strtolower($target), '.')) {
                        $matches = true;
                        break;
                    }
                }
            }

            if ($matches) {
                $status = 'active';
                $verifiedAt = ClickRepository::now();
            } else {
                $error = 'CNAME does not point to ' . $target;
            }
        }

        $statement = $this->pdo->prepare(
            'UPDATE domains SET status = :status, last_error = :last_error, verified_at = :verified_at, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':last_error' => $error,
            ':verified_at' => $verifiedAt,
            ':updated_at' => ClickRepository::now(),
            ':id' => $id,
        ]);
    }

    public function cnameTarget(): string
    {
        $configured = trim($this->config->string('cname_target'));

        if ($configured !== '') {
            return self::normalizeHostname($configured);
        }

        $host = parse_url($this->config->string('base_url'), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return 'gateway.example.com';
        }

        return self::normalizeHostname($host);
    }

    public static function normalizeHostname(string $input): string
    {
        $input = trim(strtolower($input));

        if ($input === '') {
            throw new \InvalidArgumentException('Domain is required.');
        }

        if (str_contains($input, '://')) {
            $host = parse_url($input, PHP_URL_HOST);
            $input = is_string($host) ? $host : '';
        }

        $input = trim($input, ". \t\n\r\0\x0B");

        if ($input === 'localhost' || $input === '127.0.0.1') {
            return $input;
        }

        if (filter_var($input, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('Enter a valid hostname, such as track.example.com.');
        }

        return $input;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM domains WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    private function resolveCnameRecords(string $hostname): array|false
    {
        if (is_callable($this->dnsResolver)) {
            return ($this->dnsResolver)($hostname);
        }

        return dns_get_record($hostname, DNS_CNAME);
    }
}
