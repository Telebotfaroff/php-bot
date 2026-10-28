<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use App\Database\SettingsRepository;
use App\Telegram\BotApiClient;
use PDO;
use RuntimeException;
use Throwable;

final class PostWizardService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings
    ) {}

    public function begin(int $ownerId, int $chatId): void
    {
        $dump = $this->settings->get('dump_channel_id');
        if ($dump === null || trim($dump) === '') {
            $this->telegram->sendMessage($chatId,
                "Before posting, configure the dump channel with:\n/setting set dump_channel_id -1001234567890\n\nMake the bot an administrator of that channel, then run /post again.");
            return;
        }

        $existing = $this->activeDraft($ownerId);
        if ($existing !== null) {
            $this->promptForState($chatId, $existing);
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO collection_drafts (owner_user_id, chat_id, state, status)
             VALUES (:owner, :chat, 'thumbnail', 'collecting')"
        );
        $insert->execute(['owner' => $ownerId, 'chat' => $chatId]);
        $draft = $this->getDraft((int) $this->pdo->lastInsertId());
        if ($draft !== null) $this->promptForState($chatId, $draft);
    }

    /** @param array<string, mixed> $update */
    public function handleMessage(array $update, int $ownerId, int $chatId, ?string $text): bool
    {
        $draft = $this->activeDraft($ownerId);
        if ($draft === null) return false;

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) return false;
        $draftId = (int) $draft['id'];

        if ($text !== null && strtolower(trim($text)) === '/cancel') {
            $this->cancel($draftId, $ownerId, $chatId);
            return true;
        }

        if ($draft['state'] === 'thumbnail') {
            if ($text !== null && strtolower(trim($text)) === '/skip') {
                $this->setState($draftId, 'title');
                $this->telegram->sendMessage($chatId, 'Send a title for this collection (up to 255 characters).');
                return true;
            }
            $photos = $message['photo'] ?? null;
            if (!is_array($photos) || $photos === []) {
                $this->telegram->sendMessage($chatId, 'Send a photo for the collection thumbnail, or send /skip to continue without one. Send /cancel to stop.');
                return true;
            }
            $photo = end($photos);
            $fileId = is_array($photo) && is_string($photo['file_id'] ?? null) ? $photo['file_id'] : null;
            if ($fileId === null) {
                $this->telegram->sendMessage($chatId, 'I could not read that photo. Please send it again or use /skip.');
                return true;
            }
            $statement = $this->pdo->prepare("UPDATE collection_drafts SET thumbnail_file_id = :file, state = 'title' WHERE id = :id AND owner_user_id = :owner");
            $statement->execute(['file' => $fileId, 'id' => $draftId, 'owner' => $ownerId]);
            $this->telegram->sendMessage($chatId, 'Thumbnail saved. Now send the collection title (up to 255 characters).');
            return true;
        }

        if ($draft['state'] === 'title') {
            if ($text === null || trim($text) === '' || str_starts_with(trim($text), '/')) {
                $this->telegram->sendMessage($chatId, 'Please send a text title, or use /cancel to stop.');
                return true;
            }
            $title = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '');
            if ($title === '' || strlen($title) > 255) {
                $this->telegram->sendMessage($chatId, 'The title must be between 1 and 255 bytes. Please send a shorter title.');
                return true;
            }
            $statement = $this->pdo->prepare("UPDATE collection_drafts SET title = :title, state = 'videos' WHERE id = :id AND owner_user_id = :owner");
            $statement->execute(['title' => $title, 'id' => $draftId, 'owner' => $ownerId]);
            $this->telegram->sendMessage($chatId, "Title saved. Now send one or more videos.\nThe bot will copy each video into the configured dump channel automatically.\nWhen finished, send /done. Send /cancel to discard the draft.");
            return true;
        }

        if ($draft['state'] === 'videos') {
            if ($text !== null && strtolower(trim($text)) === '/done') {
                $this->preparePreview($draftId, $ownerId, $chatId);
                return true;
            }
            if ($text !== null && strtolower(trim($text)) === '/skip') {
                $this->telegram->sendMessage($chatId, 'At least one video is required. Send a video, then /done when finished.');
                return true;
            }

            $video = $message['video'] ?? null;
            $document = $message['document'] ?? null;
            $fileId = null;
            $fileName = null;
            $kind = 'video';
            if (is_array($video) && is_string($video['file_id'] ?? null)) {
                $fileId = $video['file_id'];
                $fileName = is_string($video['file_name'] ?? null) ? $video['file_name'] : null;
            } elseif (is_array($document) && is_string($document['file_id'] ?? null)
                && str_starts_with((string) ($document['mime_type'] ?? ''), 'video/')) {
                $fileId = $document['file_id'];
                $fileName = is_string($document['file_name'] ?? null) ? $document['file_name'] : null;
                $kind = 'document';
            }

            if ($fileId === null) {
                $this->telegram->sendMessage($chatId, 'Please send a video (or a video file as a document), /done to preview, or /cancel to stop.');
                return true;
            }

            $dumpChannel = (string) $this->settings->get('dump_channel_id', '');
            $sourceMessageId = filter_var($message['message_id'] ?? null, FILTER_VALIDATE_INT);
            $sourceChatId = filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT);
            if ($sourceMessageId === false || $sourceMessageId === null || $sourceChatId === false || $sourceChatId === null) {
                $this->telegram->sendMessage($chatId, 'I could not identify the source message. Please send that video again.');
                return true;
            }

            try {
                $copied = $this->telegram->call('copyMessage', [
                    'chat_id' => $dumpChannel,
                    'from_chat_id' => $sourceChatId,
                    'message_id' => $sourceMessageId,
                ]);
                $dumpMessageId = filter_var($copied['result']['message_id'] ?? null, FILTER_VALIDATE_INT);
                if ($dumpMessageId === false || $dumpMessageId === null) {
                    throw new RuntimeException('Telegram did not return the copied message ID.');
                }
                $sequence = $this->nextSequence($draftId);
                $insert = $this->pdo->prepare(
                    'INSERT INTO collection_draft_files
                     (draft_id, sequence_no, source_file_id, dump_chat_id, dump_message_id, media_kind, file_name)
                     VALUES (:draft, :sequence, :file, :dump, :message, :kind, :name)'
                );
                $insert->execute([
                    'draft' => $draftId, 'sequence' => $sequence, 'file' => $fileId,
                    'dump' => $dumpChannel, 'message' => $dumpMessageId, 'kind' => $kind,
                    'name' => is_string($fileName) ? substr($fileName, 0, 255) : null,
                ]);
                $this->telegram->sendMessage($chatId, '✅ Video ' . $sequence . ' copied to the dump channel. Send another video or /done.');
            } catch (Throwable $exception) {
                throw $exception;
            }
            return true;
        }

        if ($draft['state'] === 'preview') {
            $this->telegram->sendMessage($chatId, 'Your draft is ready for review. Use the Publish or Cancel buttons on the preview.');
            return true;
        }

        return false;
    }

    public function handleCallback(array $update, int $userId, string $data): bool
    {
        if (!preg_match('/^post:(publish|cancel):([1-9][0-9]*)$/', $data, $match)) return false;
        $action = $match[1];
        $draftId = (int) $match[2];
        $callback = $update['callback_query'] ?? [];
        $callbackId = is_array($callback) && is_string($callback['id'] ?? null) ? $callback['id'] : null;
        $message = is_array($callback) ? ($callback['message'] ?? null) : null;
        $chatId = is_array($message) && is_numeric($message['chat']['id'] ?? null) ? (int) $message['chat']['id'] : null;

        if ($callbackId !== null) {
            try {
                $this->telegram->answerCallbackQuery($callbackId);
            } catch (\Throwable) {
                // Callback queries can expire between attempts; do not block the durable action.
            }
        }
        if ($chatId === null) return true;

        $draft = $this->getDraft($draftId);
        if ($draft === null || (int) $draft['owner_user_id'] !== $userId) {
            $this->telegram->sendMessage($chatId, 'This draft is not available to your account.');
            return true;
        }
        if ($action === 'cancel') {
            $this->cancel($draftId, $userId, $chatId);
            return true;
        }
        $this->publish($draft, $userId, $chatId);
        return true;
    }

    private function preparePreview(int $draftId, int $ownerId, int $chatId): void
    {
        $draft = $this->getDraft($draftId);
        if ($draft === null) return;
        $count = $this->fileCount($draftId);
        if ($count < 1) {
            $this->telegram->sendMessage($chatId, 'Add at least one video before finishing. Send a video, then /done.');
            return;
        }
        $this->setState($draftId, 'preview');
        $keyboard = ['inline_keyboard' => [[
            ['text' => 'Publish', 'callback_data' => 'post:publish:' . $draftId],
            ['text' => 'Cancel', 'callback_data' => 'post:cancel:' . $draftId],
        ]]];
        $summary = "📦 Collection preview\n\nTitle: " . $draft['title']
            . "\nVideos: " . $count
            . "\nThumbnail: " . ($draft['thumbnail_file_id'] !== null ? 'Yes' : 'No')
            . "\n\nChoose Publish to create the permanent link and post it to your configured post channel.";
        $options = ['reply_markup' => $keyboard];
        if (is_string($draft['thumbnail_file_id']) && $draft['thumbnail_file_id'] !== '') {
            $result = $this->telegram->call('sendPhoto', [
                'chat_id' => $chatId, 'photo' => $draft['thumbnail_file_id'],
                'caption' => $summary, 'reply_markup' => $keyboard,
            ]);
        } else {
            $result = $this->telegram->sendMessage($chatId, $summary, $options);
        }
        $previewMessageId = filter_var($result['result']['message_id'] ?? null, FILTER_VALIDATE_INT);
        if ($previewMessageId !== false && $previewMessageId !== null) {
            $statement = $this->pdo->prepare('UPDATE collection_drafts SET preview_message_id = :message WHERE id = :id');
            $statement->execute(['message' => $previewMessageId, 'id' => $draftId]);
        }
    }

    private function publish(array $draft, int $ownerId, int $chatId): void
    {
        $draftId = (int) $draft['id'];
        if ($draft['status'] === 'published') {
            $this->telegram->sendMessage($chatId, 'This collection has already been published.');
            return;
        }
        $postChannel = $this->settings->get('post_channel_id');
        if ($postChannel === null || trim($postChannel) === '') {
            $this->telegram->sendMessage($chatId, 'Configure the post channel first with /setting set post_channel_id -1001234567890, then press Publish again.');
            return;
        }
        $botUsername = trim((string) Config::get('BOT_USERNAME', ''), " @\t\n\r\0\x0B");
        if ($botUsername === '') {
            $this->telegram->sendMessage($chatId, 'BOT_USERNAME is missing from your private configuration.');
            return;
        }

        $collectionId = is_numeric($draft['published_collection_id'] ?? null)
            ? (int) $draft['published_collection_id'] : null;
        if ($collectionId === null) {
            $code = bin2hex(random_bytes(16));
            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->prepare('SELECT status, published_collection_id FROM collection_drafts WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $draftId]);
                $locked = $lock->fetch();
                if (!is_array($locked)) throw new RuntimeException('Draft no longer exists.');
                if ($locked['status'] === 'published' && is_numeric($locked['published_collection_id'])) {
                    $this->pdo->commit();
                    $this->telegram->sendMessage($chatId, 'This collection has already been published.');
                    return;
                }
                if (is_numeric($locked['published_collection_id'] ?? null)) {
                    $collectionId = (int) $locked['published_collection_id'];
                    $this->pdo->commit();
                } else {
                    $files = $this->pdo->prepare('SELECT COUNT(*) FROM collection_draft_files WHERE draft_id = :id');
                    $files->execute(['id' => $draftId]);
                    if ((int) $files->fetchColumn() < 1) throw new RuntimeException('A collection needs at least one video.');
                    $insert = $this->pdo->prepare(
                        "INSERT INTO collections (title, public_code, thumbnail_file_id, status, post_chat_id, created_by)
                         VALUES (:title, :code, :thumb, 'publishing', :post_chat, :owner)"
                    );
                    $insert->execute([
                        'title' => $draft['title'], 'code' => $code,
                        'thumb' => $draft['thumbnail_file_id'], 'post_chat' => $postChannel, 'owner' => $ownerId,
                    ]);
                    $collectionId = (int) $this->pdo->lastInsertId();
                    $copy = $this->pdo->prepare(
                        'INSERT INTO collection_files
                         (collection_id, sequence_no, source_file_id, dump_chat_id, dump_message_id, media_kind, file_name)
                         SELECT :collection_id, sequence_no, source_file_id, dump_chat_id, dump_message_id, media_kind, file_name
                         FROM collection_draft_files WHERE draft_id = :draft_id ORDER BY sequence_no'
                    );
                    $copy->execute(['collection_id' => $collectionId, 'draft_id' => $draftId]);
                    $updateDraft = $this->pdo->prepare(
                        "UPDATE collection_drafts SET status = 'publishing', published_collection_id = :collection WHERE id = :id"
                    );
                    $updateDraft->execute(['collection' => $collectionId, 'id' => $draftId]);
                    $this->pdo->commit();
                }
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }
        }

        $collection = $this->getCollection($collectionId);
        if ($collection === null) throw new RuntimeException('Collection record could not be loaded.');
        if ($collection['status'] === 'published') {
            $this->telegram->sendMessage($chatId, 'Published successfully: https://t.me/' . $botUsername . '?start=post_' . $collection['public_code']);
            return;
        }
        $deepLink = 'https://t.me/' . $botUsername . '?start=post_' . $collection['public_code'];
        $caption = "📁 " . $collection['title'] . "\n\nOpen the collection here:\n" . $deepLink;
        if (is_string($collection['thumbnail_file_id']) && $collection['thumbnail_file_id'] !== '') {
            $posted = $this->telegram->call('sendPhoto', [
                'chat_id' => $postChannel, 'photo' => $collection['thumbnail_file_id'],
                'caption' => $caption, 'reply_markup' => ['inline_keyboard' => [[
                    ['text' => 'Open collection', 'url' => $deepLink],
                ]]],
            ]);
        } else {
            $posted = $this->telegram->sendMessage($postChannel, $caption, [
                'reply_markup' => ['inline_keyboard' => [[['text' => 'Open collection', 'url' => $deepLink]]]],
            ]);
        }
        $postMessageId = filter_var($posted['result']['message_id'] ?? null, FILTER_VALIDATE_INT);
        $update = $this->pdo->prepare(
            "UPDATE collections SET status = 'published', post_message_id = :message, published_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $update->execute(['message' => $postMessageId, 'id' => $collectionId]);
        $draftUpdate = $this->pdo->prepare("UPDATE collection_drafts SET status = 'published', state = 'published' WHERE id = :id");
        $draftUpdate->execute(['id' => $draftId]);
        $this->telegram->sendMessage($chatId, "✅ Published! Your permanent collection link is:\n" . $deepLink);
    }

    private function cancel(int $draftId, int $ownerId, int $chatId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE collection_drafts SET status = 'cancelled', state = 'cancelled'
             WHERE id = :id AND owner_user_id = :owner AND status = 'collecting'"
        );
        $statement->execute(['id' => $draftId, 'owner' => $ownerId]);
        $this->telegram->sendMessage($chatId, $statement->rowCount() > 0
            ? 'Draft cancelled. Files already copied to the dump channel remain there but are not published.'
            : 'This draft cannot be cancelled because it is no longer collecting.');
    }

    private function promptForState(int $chatId, array $draft): void
    {
        $text = match ($draft['state']) {
            'thumbnail' => 'Start a new collection. Send a thumbnail photo, or /skip to continue without one. Send /cancel to stop.',
            'title' => 'Your draft is waiting for a title. Send the collection title, or /cancel to stop.',
            'videos' => 'Your draft is waiting for videos. Send one or more videos, then /done. Send /cancel to stop.',
            'preview' => 'Your draft is ready for review. Use its Publish or Cancel buttons. If the preview is missing, send /done to create it again.',
            default => 'You have an unfinished draft. Send /cancel to discard it.',
        };
        $this->telegram->sendMessage($chatId, $text);
    }

    private function activeDraft(int $ownerId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM collection_drafts WHERE owner_user_id = :owner AND status IN ('collecting','publishing')
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute(['owner' => $ownerId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function getDraft(int $draftId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM collection_drafts WHERE id = :id');
        $statement->execute(['id' => $draftId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function getCollection(?int $collectionId): ?array
    {
        if ($collectionId === null) return null;
        $statement = $this->pdo->prepare('SELECT * FROM collections WHERE id = :id');
        $statement->execute(['id' => $collectionId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function setState(int $draftId, string $state): void
    {
        $statement = $this->pdo->prepare("UPDATE collection_drafts SET state = :state WHERE id = :id AND status = 'collecting'");
        $statement->execute(['state' => $state, 'id' => $draftId]);
    }

    private function nextSequence(int $draftId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM collection_draft_files WHERE draft_id = :id');
        $statement->execute(['id' => $draftId]);
        return (int) $statement->fetchColumn();
    }

    private function fileCount(int $draftId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM collection_draft_files WHERE draft_id = :id');
        $statement->execute(['id' => $draftId]);
        return (int) $statement->fetchColumn();
    }
}
