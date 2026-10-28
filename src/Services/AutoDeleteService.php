<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\SettingsRepository;
use App\Support\Logger;
use App\Telegram\BotApiClient;
use App\Telegram\BotApiException;
use PDO;
use Throwable;

final class AutoDeleteService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings
    ) {}

    /** Process one bounded batch; MySQL advisory lock prevents overlapping cron workers. */
    public function runBatch(int $limit = 50): array
    {
        $enabled = filter_var($this->settings->get('auto_delete_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return ['enabled' => false, 'processed' => 0, 'deleted' => 0, 'failed' => 0];

        $seconds = filter_var($this->settings->get('auto_delete_after_seconds', '86400'), FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 60 || $seconds > 31536000) {
            throw new \RuntimeException('auto_delete_after_seconds must be between 60 and 31536000.');
        }
        $limit = max(1, min(200, $limit));

        $lock = $this->pdo->query("SELECT GET_LOCK('php_bot_auto_delete_worker', 0)")->fetchColumn();
        if ((int) $lock !== 1) return ['enabled' => true, 'processed' => 0, 'deleted' => 0, 'failed' => 0, 'locked' => true];

        $processed = 0;
        $deleted = 0;
        $failed = 0;
        try {
            $query = $this->pdo->prepare(
                "SELECT id, chat_id, message_id FROM delivery_outgoing_messages
                 WHERE created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :seconds SECOND)
                   AND auto_delete_attempts < 12
                 ORDER BY created_at, id LIMIT {$limit}"
            );
            $query->bindValue(':seconds', $seconds, PDO::PARAM_INT);
            $query->execute();
            foreach ($query->fetchAll() as $row) {
                $id = (int) $row['id'];
                $attempt = $this->pdo->prepare(
                    'UPDATE delivery_outgoing_messages SET auto_delete_attempts = auto_delete_attempts + 1 WHERE id = :id'
                );
                $attempt->execute(['id' => $id]);
                $processed++;
                try {
                    $this->telegram->call('deleteMessage', [
                        'chat_id' => (int) $row['chat_id'],
                        'message_id' => (int) $row['message_id'],
                    ]);
                    $this->removeQueueRow($id);
                    $deleted++;
                } catch (BotApiException $e) {
                    $description = strtolower($e->getMessage());
                    // If it is already gone, it is complete. Permission and age restrictions
                    // remain queued for bounded retries and then appear in the admin log.
                    if (str_contains($description, 'message to delete not found')
                        || str_contains($description, 'message not found')) {
                        $this->removeQueueRow($id);
                        $deleted++;
                        continue;
                    }
                    $this->recordFailure($id, $e->getMessage());
                    $failed++;
                } catch (Throwable $e) {
                    $this->recordFailure($id, $e->getMessage());
                    $failed++;
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('php_bot_auto_delete_worker')");
        }

        return ['enabled' => true, 'processed' => $processed, 'deleted' => $deleted, 'failed' => $failed];
    }

    private function removeQueueRow(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM delivery_outgoing_messages WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function recordFailure(int $id, string $message): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE delivery_outgoing_messages SET auto_delete_last_error = :error WHERE id = :id'
        );
        $statement->execute(['error' => substr($message, 0, 500), 'id' => $id]);
        Logger::warning('Auto-delete attempt failed', ['queue_id' => $id, 'error' => substr($message, 0, 250)]);
    }
}
