<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class AdminFeatureService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{id:int,title:string,code:string,file_count:int,created_at:string}> */
    public function searchCollections(int $userId, string $query, int $limit = 8): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (strlen($query) < 2 || strlen($query) > 100) {
            throw new RuntimeException('Search must be between 2 and 100 characters.');
        }
        $limit = max(1, min(10, $limit));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $statement = $this->pdo->prepare(
            "SELECT c.id, c.title, c.public_code AS code, c.created_at,
                    (SELECT COUNT(*) FROM collection_files f WHERE f.collection_id = c.id) AS file_count
             FROM collections c
             WHERE c.status = 'published' AND c.title LIKE :query ESCAPE '\\\\'
             ORDER BY c.published_at DESC, c.id DESC LIMIT {$limit}"
        );
        $statement->execute(['query' => $like]);
        $rows = $statement->fetchAll();

        $event = $this->pdo->prepare(
            'INSERT INTO bot_search_events (telegram_user_id, query_hash, result_count) VALUES (:user, :hash, :count)'
        );
        $event->execute(['user' => $userId, 'hash' => hash('sha256', mb_strtolower($query, 'UTF-8')), 'count' => count($rows)]);
        return $rows;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        $queries = [
            'users_total' => 'SELECT COUNT(*) FROM users',
            'users_active_24h' => 'SELECT COUNT(*) FROM users WHERE last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)',
            'users_banned' => 'SELECT COUNT(*) FROM users WHERE is_banned = 1',
            'collections' => "SELECT COUNT(*) FROM collections WHERE status = 'published'",
            'files' => 'SELECT COUNT(*) FROM collection_files',
            'searches_24h' => 'SELECT COUNT(*) FROM bot_search_events WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)',
            'deliveries_24h' => "SELECT COUNT(*) FROM collection_delivery_jobs WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY) AND status = 'completed'",
            'pending_jobs' => "SELECT COUNT(*) FROM telegram_update_jobs WHERE status = 'pending'",
        ];
        $stats = [];
        foreach ($queries as $key => $sql) $stats[$key] = (int) $this->pdo->query($sql)->fetchColumn();
        return $stats;
    }

    /** @return array<string,mixed>|null */
    public function user(int $userId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT u.telegram_user_id, u.username, u.first_name, u.last_name, u.created_at, u.last_seen_at,
                    u.is_banned, u.ban_reason, COALESCE(w.balance, 0) AS balance
             FROM users u LEFT JOIN wallet_accounts w ON w.telegram_user_id = u.telegram_user_id
             WHERE u.telegram_user_id = :id LIMIT 1'
        );
        $query->execute(['id' => $userId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function isBanned(int $userId): bool
    {
        $query = $this->pdo->prepare('SELECT is_banned FROM users WHERE telegram_user_id = :id');
        $query->execute(['id' => $userId]);
        return (int) $query->fetchColumn() === 1;
    }

    public function setBanned(int $targetId, int $adminId, bool $banned, string $reason = ''): bool
    {
        if ($targetId < 1 || $targetId === $adminId) throw new RuntimeException('Invalid target user ID.');
        $check = $this->pdo->prepare('SELECT telegram_user_id FROM users WHERE telegram_user_id = :id');
        $check->execute(['id' => $targetId]);
        if ($check->fetchColumn() === false) return false;
        $update = $this->pdo->prepare(
            'UPDATE users SET is_banned = :banned, banned_at = :at, banned_by = :by, ban_reason = :reason
             WHERE telegram_user_id = :id'
        );
        $update->execute([
            'banned' => $banned ? 1 : 0,
            'at' => $banned ? date('Y-m-d H:i:s') : null,
            'by' => $banned ? $adminId : null,
            'reason' => $banned ? (substr(trim($reason), 0, 255) ?: null) : null,
            'id' => $targetId,
        ]);
        return true;
    }

    /** @return array<string,int> */
    public function topSearches(int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $query = $this->pdo->query(
            'SELECT query_hash, COUNT(*) AS total FROM bot_search_events
             WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
             GROUP BY query_hash ORDER BY total DESC LIMIT ' . $limit
        );
        $result = [];
        foreach ($query->fetchAll() as $row) $result[substr((string) $row['query_hash'], 0, 10)] = (int) $row['total'];
        return $result;
    }
}
