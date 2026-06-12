<?php

declare(strict_types=1);

namespace Gateway\Services;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exceeded(string $key, int $maxHits, int $windowSeconds): bool
    {
        $now = time();
        $statement = $this->pdo->prepare('SELECT * FROM rate_limits WHERE rate_key = :rate_key LIMIT 1');
        $statement->execute([':rate_key' => $key]);
        $row = $statement->fetch();

        if (!is_array($row) || ($now - (int) $row['window_start']) >= $windowSeconds) {
            $upsert = $this->pdo->prepare(
                'REPLACE INTO rate_limits (rate_key, window_start, hits) VALUES (:rate_key, :window_start, 1)'
            );
            $upsert->execute([':rate_key' => $key, ':window_start' => $now]);

            return false;
        }

        $hits = (int) $row['hits'] + 1;
        $update = $this->pdo->prepare('UPDATE rate_limits SET hits = :hits WHERE rate_key = :rate_key');
        $update->execute([':hits' => $hits, ':rate_key' => $key]);

        return $hits > $maxHits;
    }
}
