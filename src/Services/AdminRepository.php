<?php

declare(strict_types=1);

namespace Gateway\Services;

use PDO;

final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasAdmin(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;
    }

    public function createAdmin(string $password): void
    {
        if ($this->hasAdmin()) {
            throw new \RuntimeException('Admin user already exists.');
        }

        $now = ClickRepository::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, created_at, updated_at) VALUES (:username, :password_hash, :created_at, :updated_at)'
        );
        $statement->execute([
            ':username' => 'admin',
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function verifyPassword(string $password): bool
    {
        $statement = $this->pdo->query('SELECT * FROM admin_users ORDER BY id ASC LIMIT 1');
        $admin = $statement->fetch();

        if (!is_array($admin)) {
            return false;
        }

        $hash = (string) ($admin['password_hash'] ?? '');
        $valid = password_verify($password, $hash);

        if ($valid && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $update = $this->pdo->prepare('UPDATE admin_users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
            $update->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':updated_at' => ClickRepository::now(),
                ':id' => $admin['id'],
            ]);
        }

        return $valid;
    }
}
