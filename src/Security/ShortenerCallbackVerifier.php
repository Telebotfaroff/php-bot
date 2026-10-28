<?php

declare(strict_types=1);

namespace App\Security;

final class ShortenerCallbackVerifier
{
    public static function sign(string $providerCode, string $token, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $providerCode . '.' . $token, $secret);
    }

    public static function verify(
        string $providerCode,
        string $token,
        string $timestamp,
        string $signature,
        string $expectedProvider,
        string $secret,
        ?int $now = null
    ): bool {
        if ($secret === '' || strlen($secret) < 32
            || !preg_match('/^[a-z][a-z0-9_-]{1,39}$/i', $providerCode)
            || !preg_match('/^[a-f0-9]{64}$/i', $token)
            || !preg_match('/^[0-9]{10}$/', $timestamp)
            || !preg_match('/^[a-f0-9]{64}$/i', $signature)
            || !hash_equals($expectedProvider, $providerCode)) {
            return false;
        }

        $issuedAt = (int) $timestamp;
        $now ??= time();
        if (abs($now - $issuedAt) > 300) return false;

        $expected = self::sign($providerCode, strtolower($token), $issuedAt, $secret);
        return hash_equals($expected, strtolower($signature));
    }
}
