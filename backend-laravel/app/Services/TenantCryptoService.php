<?php

namespace App\Services;

use App\Models\Tenant;

class TenantCryptoService
{
    /**
     * Derive a tenant-specific 256-bit encryption key using HKDF-SHA256 (M11).
     */
    public static function deriveTenantKey(Tenant $tenant): string
    {
        if (empty($tenant->encryption_key_salt)) {
            $tenant->update(['encryption_key_salt' => bin2hex(random_bytes(16))]);
        }

        $masterKey = (string) config('app.key');
        if (str_starts_with($masterKey, 'base64:')) {
            $decoded = base64_decode(substr($masterKey, 7), true);
            if ($decoded !== false) {
                $masterKey = $decoded;
            }
        }

        return hash_hkdf('sha256', $masterKey, 32, "tenant:{$tenant->id}:{$tenant->encryption_key_salt}");
    }

    /**
     * Encrypt payload using the tenant's isolated AES-256-GCM key.
     */
    public static function encryptForTenant(Tenant $tenant, string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::deriveTenantKey($tenant),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Tenant encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt payload using the tenant's isolated AES-256-GCM key.
     * Ciphertext encrypted for Tenant A fails authentication tag verification under Tenant B.
     */
    public static function decryptForTenant(Tenant $tenant, string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid tenant ciphertext.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::deriveTenantKey($tenant),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Tenant decryption failed: invalid key or tampered payload.');
        }

        return $plaintext;
    }
}
