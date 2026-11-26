<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically random 160-bit Base32 TOTP secret.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        $binary = '';
        foreach (str_split($raw) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $secret .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    /**
     * Compute a 6-digit RFC 6238 TOTP code for a given time slice (30s period).
     */
    public static function getCode(string $base32Secret, ?int $timeSlice = null): string
    {
        $slice = $timeSlice ?? (int) floor(time() / 30);
        $secretKey = self::base32Decode($base32Secret);
        $timeBytes = pack('N*', 0) . pack('N*', $slice);
        $hash = hash_hmac('sha1', $timeBytes, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-submitted 6-digit TOTP code with +/-1 time-step tolerance and replay prevention.
     */
    public static function verifyCode(string $base32Secret, string $code, int $window = 1, ?int $userId = null): bool
    {
        $clean = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', (string) $clean)) {
            return false;
        }

        $currentSlice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $slice = $currentSlice + $i;
            $expected = self::getCode($base32Secret, $slice);
            if (hash_equals($expected, (string) $clean)) {
                if ($userId !== null) {
                    $cacheKey = "totp_used:{$userId}:{$slice}";
                    if (!Cache::add($cacheKey, true, 120)) {
                        return false; // Replay of already-used TOTP code rejected
                    }
                }
                return true;
            }
        }

        return false;
    }

    public static function getProvisioningUri(string $issuer, string $accountName, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $binary = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos !== false) {
                $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
