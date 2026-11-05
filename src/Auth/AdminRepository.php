<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Admin accounts. Email addresses are stored trimmed and in lower case.
 *
 * @author shimomo
 */
final class AdminRepository
{
    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function create(string $name, string $email, string $password): int
    {
        $this->db->execute(
            'INSERT INTO admins (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)',
            [$name, self::normalize($email), password_hash($password, PASSWORD_DEFAULT), Time::format($this->clock->now())],
        );

        return $this->db->lastInsertId();
    }

    /**
     * @return array{id: int, name: string, email: string, password_hash: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchOne('SELECT id, name, email, password_hash FROM admins WHERE email = ?', [self::normalize($email)]);

        return $row === null ? null : self::admin($row);
    }

    /**
     * @return array{id: int, name: string, email: string, password_hash: string}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT id, name, email, password_hash FROM admins WHERE id = ?', [$id]);

        return $row === null ? null : self::admin($row);
    }

    /**
     * @return list<array{id: int, name: string, email: string, created_at: string}>
     */
    public function all(): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'created_at' => (string) $row['created_at'],
        ], $this->db->fetchAll('SELECT id, name, email, created_at FROM admins ORDER BY id'));
    }

    /**
     * A deleted admin is logged out on their next request: AuthMiddleware looks the id up every time.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM admins WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM admins');
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, email: string, password_hash: string}
     */
    private static function admin(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
        ];
    }
}
