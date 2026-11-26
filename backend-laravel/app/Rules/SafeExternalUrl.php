<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeExternalUrl implements ValidationRule
{
    public function __construct(protected bool $allowRelative = false)
    {
    }

    /**
     * Validate that a URL is safe from SSRF (no internal/metadata IPs or unsafe schemes).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $url = trim($value);

        // Allow safe relative paths (e.g., /images/dish.jpg) without protocol/host
        if ($this->allowRelative && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $fail("The {$attribute} must be a valid URL.");
            return;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || empty($host)) {
            $fail("The {$attribute} must use http or https scheme.");
            return;
        }

        $blockedHosts = ['localhost', 'localhost.localdomain', 'metadata.google.internal', '0.0.0.0'];
        if (in_array($host, $blockedHosts, true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            $fail("The {$attribute} points to a restricted internal host.");
            return;
        }

        // Strip IPv6 brackets
        $ipCandidate = trim($host, '[]');
        if (filter_var($ipCandidate, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $ipCandidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($isPublic === false) {
                $fail("The {$attribute} cannot point to a private, loopback, or link-local IP address.");
                return;
            }
        }
    }
}
