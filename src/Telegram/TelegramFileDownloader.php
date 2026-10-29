<?php

declare(strict_types=1);

namespace App\Telegram;

use RuntimeException;

final class TelegramFileDownloader
{
    public function __construct(private readonly string $token, private readonly string $apiBase = 'https://api.telegram.org') {}

    public function download(string $fileId, string $destination, int $maxBytes = 8000000): void
    {
        if ($fileId === '' || strlen($fileId) > 512 || !function_exists('curl_init')) {
            throw new RuntimeException('Invalid file request.');
        }
        $api = rtrim($this->apiBase, '/') . '/bot' . $this->token . '/getFile';
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['file_id' => $fileId]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($response) ? json_decode($response, true) : null;
        $filePath = is_array($json) ? ($json['result']['file_path'] ?? null) : null;
        if ($status < 200 || $status >= 300 || !is_string($filePath) || $filePath === '' || str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
            throw new RuntimeException('Telegram did not return a valid file path.');
        }

        $fp = fopen($destination, 'wb');
        if ($fp === false) throw new RuntimeException('Could not open temporary file.');
        $ch = curl_init(rtrim($this->apiBase, '/') . '/file/bot' . $this->token . '/' . $filePath);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        $size = filesize($destination);
        if ($ok !== true || $status < 200 || $status >= 300 || !is_int($size) || $size < 2 || $size > $maxBytes) {
            @unlink($destination);
            throw new RuntimeException('Uploaded backup is invalid or exceeds the size limit.');
        }
    }
}
