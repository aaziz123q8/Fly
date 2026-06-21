<?php

declare(strict_types=1);

namespace App\Helpers;

class SecurityHelper
{
    private static string $cipher = 'AES-256-CBC';

    /**
     * Encrypt a string using AES-256-CBC.
     * Reads APP_MASTER_KEY from environment (hex-encoded 32 bytes = 64 chars).
     *
     * @throws \RuntimeException if the master key is missing or invalid.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getMasterKey();
        $iv  = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$cipher));

        $ciphertext = openssl_encrypt($plaintext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . ':' . $ciphertext);
    }

    /**
     * Decrypt a string previously encrypted with encrypt().
     *
     * @throws \RuntimeException on decryption failure.
     */
    public static function decrypt(string $encoded): string
    {
        $key  = self::getMasterKey();
        $data = base64_decode($encoded, true);

        if ($data === false) {
            throw new \RuntimeException('Invalid base64 payload.');
        }

        $separatorPos = strpos($data, ':');
        if ($separatorPos === false) {
            throw new \RuntimeException('Malformed encrypted payload.');
        }

        $iv         = substr($data, 0, $separatorPos);
        $ciphertext = substr($data, $separatorPos + 1);

        $plaintext = openssl_decrypt($ciphertext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — key mismatch or corrupted data.');
        }

        return $plaintext;
    }

    /**
     * Mask sensitive data for logging (shows first 4 and last 4 chars).
     */
    public static function maskSensitive(string $value, int $visibleChars = 4): string
    {
        $length = mb_strlen($value);

        if ($length <= $visibleChars * 2) {
            return str_repeat('*', $length);
        }

        $start = mb_substr($value, 0, $visibleChars);
        $end   = mb_substr($value, -$visibleChars);

        return $start . str_repeat('*', $length - ($visibleChars * 2)) . $end;
    }

    /**
     * Generate a cryptographically secure random token.
     */
    public static function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Constant-time string comparison to prevent timing attacks.
     */
    public static function safeCompare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    // -------------------------------------------------------------------------

    private static function getMasterKey(): string
    {
        $hex = getenv('APP_MASTER_KEY');

        if (empty($hex) || strlen($hex) !== 64) {
            throw new \RuntimeException(
                'APP_MASTER_KEY environment variable is missing or invalid (must be 64 hex chars).'
            );
        }

        return hex2bin($hex);
    }
}
