<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Logger;
use App\Telegram\UpdateRouter;
use PDO;
use Throwable;

final class UpdateOutbox
{
    public function __construct(private readonly PDO $pdo, private readonly UpdateRouter $router) {}

    public function dispatchOne(int $updateId): bool
    {
        $job = $this->claim($updateId);
        if ($job === null) return $this->isCompleted($updateId);

        try {
            $update = json_decode($job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($update)) throw new \RuntimeException('Stored update payload is invalid.');
            $this->router->dispatch($update);

            $done = $this->pdo->prepare(
                "UPDATE telegram_update_jobs SET status = 'done', locked_until = NULL, last_error = NULL
                 WHERE update_id = :id AND status = 'processing'"
            );
            $done->execute(['id' => $updateId]);
            $mark = $this->pdo->prepare(
                "UPDATE telegram_updates SET status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE update_id = :id"
            );
            $mark->execute(['id' => $updateId]);
            return true;
        } catch (Throwable $exception) {
            $this->releaseForRetry($updateId, $job['attempts'] + 1, get_class($exception));
            Logger::exception('Telegram update dispatch failed; queued for retry', $exception);
            return false;
        }
    }

    public function dispatchDue(int $limit = 25): int
    {
        $limit = max(1, min(100, $limit));
        $query = $this->pdo->query(
            "SELECT update_id FROM telegram_update_jobs
             WHERE (status = 'pending' AND available_at <= CURRENT_TIMESTAMP)
                OR (status = 'processing' AND locked_until < CURRENT_TIMESTAMP)
             ORDER BY update_id ASC LIMIT " . $limit
        );
        $completed = 0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->dispatchOne((int) $id)) $completed++;
        }
        return $completed;
    }

    /** @return array{payload_json:string, attempts:int}|null */
    private function claim(int $updateId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT payload_json, attempts, status,
                        (available_at <= CURRENT_TIMESTAMP) AS is_due,
                        (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP) AS lock_expired
                 FROM telegram_update_jobs WHERE update_id = :id FOR UPDATE'
            );
            $query->execute(['id' => $updateId]);
            $row = $query->fetch();
            if (!is_array($row) || $row['status'] === 'done'
                || ($row['status'] === 'pending' && !(bool) $row['is_due'])
                || ($row['status'] === 'processing' && !(bool) $row['lock_expired'])
                || !in_array($row['status'], ['pending', 'processing'], true)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE telegram_update_jobs SET status = 'processing', attempts = attempts + 1,
                 locked_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 90 SECOND) WHERE update_id = :id"
            );
            $update->execute(['id' => $updateId]);
            $this->pdo->commit();
            return ['payload_json' => (string) $row['payload_json'], 'attempts' => (int) $row['attempts']];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function releaseForRetry(int $updateId, int $attempt, string $error): void
    {
        $delay = min(3600, 15 * (2 ** min(8, max(0, $attempt - 1))));
        $statement = $this->pdo->prepare(
            "UPDATE telegram_update_jobs SET status = 'pending', locked_until = NULL,
             available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :delay SECOND), last_error = :error
             WHERE update_id = :id AND status = 'processing'"
        );
        $statement->bindValue('delay', $delay, PDO::PARAM_INT);
        $statement->bindValue('error', substr($error, 0, 255), PDO::PARAM_STR);
        $statement->bindValue('id', $updateId, PDO::PARAM_INT);
        $statement->execute();
        $mark = $this->pdo->prepare(
            "UPDATE telegram_updates SET status = 'pending', processed_at = NULL WHERE update_id = :id"
        );
        $mark->execute(['id' => $updateId]);
    }

    private function isCompleted(int $updateId): bool
    {
        $query = $this->pdo->prepare('SELECT status FROM telegram_update_jobs WHERE update_id = :id');
        $query->execute(['id' => $updateId]);
        return $query->fetchColumn() === 'done';
    }
}
