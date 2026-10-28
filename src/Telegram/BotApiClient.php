<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config\Config;
use RuntimeException;

final class BotApiClient
{
    private string $token;
    private string $apiBase;

    public function __construct(?string $token = null, ?string $apiBase = null)
    {
        $this->token = $token ?? Config::requireString('BOT_TOKEN');
        $this->apiBase = rtrim(
            $apiBase ?? (string) Config::get('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            '/'
        );
    }

    public function call(string $method, array $parameters = []): array
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,63}$/', $method)) {
            throw new RuntimeException('Invalid Telegram Bot API method name.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Telegram API calls.');
        }

        $url = $this->apiBase . '/bot' . $this->token . '/' . $method;
        $payload = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new RuntimeException('Could not encode Telegram API request.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($body)) {
            throw new RuntimeException('Telegram API transport failed: ' . $curlError);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Telegram API returned invalid JSON (HTTP ' . $httpStatus . ').');
        }
        if ($httpStatus < 200 || $httpStatus >= 300 || ($decoded['ok'] ?? false) !== true) {
            $description = is_string($decoded['description'] ?? null)
                ? $decoded['description'] : 'Unknown Telegram API error';
            $code = is_numeric($decoded['error_code'] ?? null)
                ? (int) $decoded['error_code'] : $httpStatus;
            throw new BotApiException('Telegram API request failed: ' . $description, $code, $decoded);
        }
        return $decoded;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }


    public function sendDocument(int|string $chatId, string $path, string $filename, string $caption = ''): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Backup file is unavailable.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Telegram uploads.');
        }

        $curl = curl_init($this->apiBase . '/bot' . $this->token . '/sendDocument');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize Telegram upload.');
        }
        $upload = new \CURLFile($path, 'application/json', basename($filename));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => (string) $chatId,
                'caption' => $caption,
                'document' => $upload,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($body)) {
            throw new RuntimeException('Telegram upload transport failed: ' . $curlError);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Telegram upload returned invalid JSON (HTTP ' . $httpStatus . ').');
        }
        if ($httpStatus < 200 || $httpStatus >= 300 || ($decoded['ok'] ?? false) !== true) {
            $description = is_string($decoded['description'] ?? null) ? $decoded['description'] : 'Unknown Telegram API error';
            $code = is_numeric($decoded['error_code'] ?? null) ? (int) $decoded['error_code'] : $httpStatus;
            throw new BotApiException('Telegram upload failed: ' . $description, $code, $decoded);
        }
        return $decoded;
    }

    public function answerCallbackQuery(string $callbackQueryId, array $options = []): array
    {
        return $this->call('answerCallbackQuery', array_merge([
            'callback_query_id' => $callbackQueryId,
        ], $options));
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query', 'channel_post', 'edited_message'],
            'drop_pending_updates' => false,
        ]);
    }
}
