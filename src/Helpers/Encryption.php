<?php

declare(strict_types=1);

class Encryption
{
    private const METHOD = 'AES-256-CBC';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv  = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, self::METHOD, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $ciphertext): string
    {
        $key  = self::key();
        $data = base64_decode($ciphertext);
        $iv   = substr($data, 0, 16);
        $enc  = substr($data, 16);
        return openssl_decrypt($enc, self::METHOD, $key, 0, $iv) ?: '';
    }

    private static function key(): string
    {
        return hash('sha256', ENCRYPTION_KEY, true);
    }
}
