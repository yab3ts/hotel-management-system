<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityAlert;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TotpService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const COMMON_BREACHED_PASSWORDS = [
        'password123', 'admin1234', 'welcome123', 'qwerty123', '12345678a', 'passw0rd!', 'letmein123', 'changeme123',
    ];

    private function isPasswordBreached(string $password): bool
    {
        if (in_array(strtolower($password), self::COMMON_BREACHED_PASSWORDS, true)) {
            return true;
        }

        if (config('app.hibp_check_enabled')) {
            try {
                $sha1 = strtoupper(sha1($password));
                $prefix = substr($sha1, 0, 5);
                $suffix = substr($sha1, 5);
                $res = Http::timeout(2)->get("https://api.pwnedpasswords.com/range/{$prefix}");
                if ($res->successful() && str_contains($res->body(), $suffix . ':')) {
                    return true;
                }
            } catch (\Throwable) {
                // Fail open if HIBP is unreachable so auth stays available
            }
        }

        return false;
    }

    /**
     * User registration (multi-tenant aware, M22 cross-tenant enumeration protected).
     */
    public function signup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'full_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($this->isPasswordBreached($validated['password'])) {
            throw ValidationException::withMessages([
                'password' => ['This password has appeared in known data breaches. Please choose a more unique password.'],
            ]);
        }

        $email = strtolower(trim($validated['email']));
        $tenant = TenantContext::getTenant() ?? Tenant::first();
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $sameTenant = $tenant && $existing->tenants()->where('tenants.id', $tenant->id)->exists();
            if ($sameTenant) {
                throw ValidationException::withMessages([
                    'email' => ['The email has already been taken.'],
                ]);
            }

            // Security (M22): Do not reveal whether an email is already registered under another tenant
            return response()->json([
                'message' => 'If eligible, registration confirmation instructions have been sent to your email.',
            ], 202);
        }

        $name = strip_tags($validated['full_name'] ?? $validated['name'] ?? explode('@', $email)[0]);

        // Security Hardening (CWE-269): Public signup strictly assigns 'customer' role.
        $role = 'customer';
        $assignedRoles = ['customer'];

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'current_tenant_id' => $tenant?->id,
        ]);

        if ($tenant) {
            foreach ($assignedRoles as $r) {
                $tenant->users()->attach($user->id, ['role' => $r]);
            }
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'role' => $role,
                'roles' => $assignedRoles,
                'tenant_id' => $tenant?->id,
            ],
            'token' => $token,
            'session' => ['access_token' => $token],
            'roles' => $assignedRoles,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
        ], 201);
    }

    /**
     * Issue a server-signed stateless CAPTCHA challenge (#3 Credential stuffing defense).
     */
    public function captchaChallenge(): JsonResponse
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $exp = time() + 300;
        $answer = (string) ($a + $b);
        $payload = base64_encode(json_encode(['exp' => $exp, 'ans' => hash_hmac('sha256', $answer, (string) config('app.key'))]));
        $sig = hash_hmac('sha256', $payload, (string) config('app.key'));

        return response()->json([
            'question' => "What is {$a} + {$b}?",
            'captcha_token' => "{$payload}.{$sig}",
            'expires_at' => date(DATE_ISO8601, $exp),
        ]);
    }

    private function verifyCaptcha(?string $token, ?string $answer): bool
    {
        if (empty($token) || empty($answer) || !str_contains($token, '.')) {
            return false;
        }
        [$payload, $sig] = explode('.', $token, 2);
        $expectedSig = hash_hmac('sha256', $payload, (string) config('app.key'));
        if (!hash_equals($expectedSig, $sig)) {
            return false;
        }
        $data = json_decode(base64_decode($payload, true) ?: '', true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return false;
        }
        $expectedAns = hash_hmac('sha256', trim($answer), (string) config('app.key'));
        return hash_equals((string) ($data['ans'] ?? ''), $expectedAns);
    }

    /**
     * User login with brute-force lockout, CAPTCHA gate (#3), after-hours anomaly detection (#127), and MFA (#4).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'totp_code' => ['nullable', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string', 'max:16'],
        ]);

        $email = strtolower(trim($validated['email']));
        $throttleKey = 'login-attempt:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            SecurityAlert::raise('brute_force_lockout', "Login locked out for {$email} after repeated failures.", 'high', null, ['email' => $email]);
            return response()->json([
                'error' => "Too many login attempts. Account temporarily locked for {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        // Security (#3): Require CAPTCHA verification once 3 failed attempts occur
        if (RateLimiter::attempts($throttleKey) >= 3) {
            if (!$this->verifyCaptcha($validated['captcha_token'] ?? null, $validated['captcha_answer'] ?? null)) {
                RateLimiter::hit($throttleKey, 60);
                return response()->json([
                    'error' => 'CAPTCHA verification required after repeated failed login attempts.',
                    'captcha_required' => true,
                ], 428);
            }
        }

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'error' => 'Invalid email or password',
            ], 401);
        }

        // Security (#12): Terminated / disabled accounts are rejected immediately
        if ($user->is_active === false) {
            $user->tokens()->delete();
            return response()->json([
                'error' => 'This account has been disabled. Please contact your administrator.',
            ], 403);
        }

        $tenant = TenantContext::getTenant();
        if (!$tenant && $user->current_tenant_id) {
            $tenant = Tenant::find($user->current_tenant_id);
            TenantContext::setTenant($tenant);
        }
        if (!$tenant) {
            $tenant = Tenant::first();
            TenantContext::setTenant($tenant);
        }

        $roles = $user->rolesForTenant($tenant?->id);
        $isPrivileged = !empty(array_intersect($roles, ['admin', 'manager']));

        // Security (#4 MFA): Verify TOTP when MFA is enabled on the user (or globally enforced for admin/manager)
        if ($user->mfa_enabled) {
            $totpValid = !empty($validated['totp_code']) && !empty($user->mfa_secret)
                && TotpService::verifyCode($user->mfa_secret, $validated['totp_code'], 1, $user->id);

            $recoveryValid = false;
            if (!$totpValid && !empty($validated['recovery_code']) && is_array($user->mfa_recovery_codes)) {
                $remaining = [];
                foreach ($user->mfa_recovery_codes as $hashedCode) {
                    if (!$recoveryValid && Hash::check($validated['recovery_code'], $hashedCode)) {
                        $recoveryValid = true;
                        continue; // consume one-time recovery code
                    }
                    $remaining[] = $hashedCode;
                }
                if ($recoveryValid) {
                    $user->update(['mfa_recovery_codes' => $remaining]);
                }
            }

            if (!$totpValid && !$recoveryValid) {
                RateLimiter::hit($throttleKey, 60);
                return response()->json([
                    'error' => 'Multi-factor authentication (TOTP) verification required.',
                    'mfa_required' => true,
                ], 403);
            }
        } elseif ($isPrivileged && config('app.enforce_admin_mfa')) {
            return response()->json([
                'error' => 'MFA setup is mandatory for admin and manager accounts before login.',
                'mfa_setup_required' => true,
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        // Security (#127): Flag after-hours staff/admin login anomaly (00:00 - 05:00 local time)
        $loginHour = config('app.test_login_hour') !== null
            ? (int) config('app.test_login_hour')
            : (int) now($tenant?->timezone ?? 'UTC')->hour;
        if (!empty(array_intersect($roles, ['admin', 'manager', 'waiter', 'kitchen', 'kitchen_staff', 'staff'])) && $loginHour >= 0 && $loginHour < 5) {
            SecurityAlert::raise(
                'after_hours_login',
                "After-hours staff login detected for {$user->email} at hour {$loginHour}:00.",
                'medium',
                $user->id,
                ['hour' => $loginHour, 'roles' => $roles],
                $tenant?->id
            );
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'role' => $roles[0] ?? 'customer',
                'roles' => $roles,
                'tenant_id' => $tenant?->id,
                'must_change_password' => (bool) ($user->must_change_password ?? false),
            ],
            'token' => $token,
            'session' => ['access_token' => $token],
            'roles' => $roles,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'profile' => [
                'id' => $user->id,
                'full_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url,
                'preferences' => ['theme' => 'light', 'notifications' => true],
            ],
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->is_active === false) {
            $user->tokens()->delete();
            return response()->json(['error' => 'This account has been disabled.'], 403);
        }

        $tenant = TenantContext::getTenant();
        $roles = $user->rolesForTenant($tenant?->id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'phone' => $user->phone,
                'roles' => $roles,
                'must_change_password' => (bool) ($user->must_change_password ?? false),
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'roles' => $roles,
        ]);
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Stolen device protection: revoke ALL active access tokens for the authenticated user (#119).
     */
    public function revokeAllTokens(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user?->tokens()->delete() ?? 0;
        AuditLog::record('all_user_tokens_revoked', $user, ['revoked_count' => $count], $user?->id);

        return response()->json([
            'message' => 'All active sessions and device tokens have been wiped.',
            'revoked_count' => $count,
        ]);
    }

    /**
     * Switch active tenant for user (strictly verifies tenant membership).
     */
    public function switchTenant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
        ]);

        $tenant = Tenant::where('is_active', true)->findOrFail($validated['tenant_id']);
        $user = $request->user();

        // Security (M2): Reject cross-tenant switching unless user belongs to target tenant
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json([
                'error' => 'Forbidden: You are not a member of the requested tenant organization.',
            ], 403);
        }

        $user->update(['current_tenant_id' => $tenant->id]);
        TenantContext::setTenant($tenant);

        return response()->json([
            'message' => 'Active branch switched successfully',
            'tenant' => $tenant,
            'roles' => $user->rolesForTenant($tenant->id),
        ]);
    }

    /**
     * Request password reset token (constant generic response prevents account enumeration).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if ($user && $user->is_active !== false) {
            $plainToken = bin2hex(random_bytes(32)); // 256-bit cryptographic one-time token

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($plainToken),
                    'created_at' => now(),
                ]
            );
        }

        // Identical generic message regardless of whether the email exists (#11)
        return response()->json([
            'message' => 'If an account exists for that email, password reset instructions have been sent.',
        ]);
    }

    /**
     * Reset password using random one-time 15-minute expiring token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);

        $email = strtolower(trim($validated['email']));
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record || !Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'error' => 'Invalid or expired password reset token.',
            ], 422);
        }

        // Strict 15-minute expiry (#10)
        if (now()->diffInMinutes($record->created_at, true) > 15) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'error' => 'Invalid or expired password reset token.',
            ], 422);
        }

        $user = User::where('email', $email)->firstOrFail();
        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        // Invalidate one-time reset token and revoke all active API tokens
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password has been reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * Initialize TOTP MFA secret and one-time recovery codes (#4).
     */
    public function mfaSetup(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = TotpService::generateSecret();

        $plainRecovery = [];
        $hashedRecovery = [];
        for ($i = 0; $i < 6; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $plainRecovery[] = $code;
            $hashedRecovery[] = Hash::make($code);
        }

        $user->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $hashedRecovery,
            'mfa_enabled' => false,
        ]);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => TotpService::getProvisioningUri('Tableza', $user->email, $secret),
            'recovery_codes' => $plainRecovery,
        ]);
    }

    /**
     * Confirm and activate TOTP MFA after verifying first authenticator code (#4).
     */
    public function mfaEnable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'totp_code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        if (empty($user->mfa_secret) || !TotpService::verifyCode($user->mfa_secret, $validated['totp_code'], 1, $user->id)) {
            return response()->json([
                'error' => 'Invalid TOTP verification code.',
            ], 422);
        }

        $user->update(['mfa_enabled' => true]);
        AuditLog::record('mfa_enabled', $user, ['email' => $user->email]);

        return response()->json(['message' => 'TOTP MFA enabled successfully.']);
    }

    /**
     * Disable TOTP MFA (requires current password and valid TOTP code).
     */
    public function mfaDisable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'totp_code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Invalid password.'], 401);
        }
        if (empty($user->mfa_secret) || !TotpService::verifyCode($user->mfa_secret, $validated['totp_code'], 1, $user->id)) {
            return response()->json(['error' => 'Invalid TOTP verification code.'], 422);
        }

        $user->update([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
        ]);
        AuditLog::record('mfa_disabled', $user, ['email' => $user->email]);

        return response()->json(['message' => 'TOTP MFA disabled successfully.']);
    }

    /**
     * Target user grants time-limited explicit consent for support impersonation (M24).
     */
    public function grantImpersonationConsent(Request $request): JsonResponse
    {
        $user = $request->user();
        $consentToken = bin2hex(random_bytes(16));
        $expiresAt = now()->addMinutes(15);

        $user->update([
            'impersonation_consent_token' => Hash::make($consentToken),
            'impersonation_consented_until' => $expiresAt,
        ]);

        AuditLog::record('impersonation_consent_granted', $user, [
            'user_id' => $user->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $user->id);

        return response()->json([
            'consent_token' => $consentToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Platform super-admin starts a strictly time-limited, consented, audited support session (M24).
     */
    public function startImpersonation(Request $request): JsonResponse
    {
        $supportUser = $request->user();
        if (!$supportUser || !$supportUser->is_super_admin) {
            return response()->json([
                'error' => 'Forbidden: Only platform super-administrators may initiate support impersonation.',
            ], 403);
        }

        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'consent_token' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $targetUser = User::findOrFail($validated['target_user_id']);
        if ($targetUser->is_active === false) {
            return response()->json(['error' => 'Cannot impersonate a disabled user account.'], 403);
        }

        if (
            empty($targetUser->impersonation_consent_token) ||
            !$targetUser->impersonation_consented_until ||
            $targetUser->impersonation_consented_until->isPast() ||
            !Hash::check($validated['consent_token'], $targetUser->impersonation_consent_token)
        ) {
            return response()->json([
                'error' => 'Forbidden: Valid, unexpired explicit user consent is required before impersonation.',
            ], 403);
        }

        // Consume one-time consent token immediately
        $targetUser->update([
            'impersonation_consent_token' => null,
            'impersonation_consented_until' => null,
        ]);

        $expiresAt = now()->addMinutes(15);
        $impersonationToken = $targetUser->createToken(
            "support-impersonation-by-{$supportUser->id}",
            ['impersonation'],
            $expiresAt
        )->plainTextToken;

        AuditLog::record('impersonation_started', $targetUser, [
            'support_user_id' => $supportUser->id,
            'support_email' => $supportUser->email,
            'target_user_id' => $targetUser->id,
            'reason' => strip_tags($validated['reason']),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $supportUser->id);

        SecurityAlert::raise(
            'impersonation_started',
            "Support admin {$supportUser->email} started 15-min consented impersonation for {$targetUser->email}.",
            'high',
            $targetUser->id,
            ['support_user_id' => $supportUser->id, 'reason' => strip_tags($validated['reason'])],
            $targetUser->current_tenant_id
        );

        return response()->json([
            'token' => $impersonationToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'impersonated_user_id' => $targetUser->id,
        ]);
    }

    /**
     * Terminate an active impersonation token immediately (M24).
     */
    public function stopImpersonation(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLog::record('impersonation_stopped', $user, ['target_user_id' => $user?->id]);
        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Impersonation session terminated.']);
    }
}
