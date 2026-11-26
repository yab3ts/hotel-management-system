<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\TableSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentGatewayService
{
    protected string $webhookSecret;

    public function __construct(?string $secret = null)
    {
        $configured = $secret ?? config('services.payment_gateway.secret');
        if (app()->isProduction() && (empty($configured) || $configured === 'cafex_gw_secret_sandbox_key_99')) {
            throw new \RuntimeException('Production Security Error: PAYMENT_WEBHOOK_SECRET must be configured with a cryptographically secure key in production environments.');
        }
        $this->webhookSecret = $configured ?? 'cafex_gw_secret_sandbox_key_99';
    }

    /**
     * Compute HMAC-SHA256 signature for a webhook payload.
     */
    public function computeSignature(string $payload, ?string $secretOverride = null): string
    {
        return hash_hmac('sha256', $payload, $secretOverride ?? $this->webhookSecret);
    }

    /**
     * Constant-time comparison of webhook signature to prevent timing attacks.
     * Supports per-tenant webhook secrets (M18) and 300s timestamp replay defense.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, ?string $secretOverride = null): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        // Support formats like "t=1700000000,v1=abc..." or direct sha256 string
        $signature = $signatureHeader;
        if (str_contains($signatureHeader, 'v1=')) {
            preg_match('/v1=([a-f0-9]+)/', $signatureHeader, $matches);
            $signature = $matches[1] ?? '';

            if (preg_match('/t=([0-9]+)/', $signatureHeader, $tMatches)) {
                $timestamp = (int) $tMatches[1];
                // Reject if timestamp is older than 5 minutes or in the distant future
                if (abs(time() - $timestamp) > 300) {
                    return false;
                }
            }
        }

        $expected = $this->computeSignature($rawPayload, $secretOverride);
        return hash_equals($expected, $signature);
    }

    /**
     * Process verified webhook event with deduplication and amount/currency validation.
     */
    public function processEvent(array $event): array
    {
        $eventType = $event['event'] ?? $event['type'] ?? 'unknown';
        $eventId = $event['id'] ?? $event['event_id'] ?? null;
        $data = $event['data'] ?? [];

        // 1. Anti-Replay Event ID Deduplication
        if ($eventId) {
            $cacheKey = "gw_processed_event_{$eventId}";
            if (Cache::has($cacheKey)) {
                return [
                    'status' => 'already_processed',
                    'event_id' => $eventId,
                ];
            }
            Cache::put($cacheKey, true, now()->addDay());
        }

        $txnId = $data['transaction_id'] ?? $data['id'] ?? null;
        if (!$txnId) {
            return [
                'status' => 'ignored',
                'reason' => 'Missing transaction_id in webhook payload',
            ];
        }

        return DB::transaction(function () use ($eventType, $data, $txnId, $eventId) {
            $payment = Payment::where('transaction_reference', $txnId)
                ->orWhere('id', is_numeric($txnId) ? $txnId : -1)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                return [
                    'status' => 'not_found',
                    'transaction_id' => $txnId,
                ];
            }

            // 2. Amount and Currency Integrity Verification
            if (isset($data['amount'])) {
                $webhookAmount = (float) $data['amount'];
                if (abs($webhookAmount - (float) $payment->amount) > 0.01) {
                    AuditLog::record('gateway_amount_tamper_rejected', $payment, [
                        'expected_amount' => $payment->amount,
                        'received_amount' => $webhookAmount,
                        'transaction_reference' => $txnId,
                    ]);

                    return [
                        'status' => 'rejected',
                        'reason' => "Amount mismatch: expected {$payment->amount}, received {$webhookAmount}",
                    ];
                }
            }

            if (isset($data['currency']) && strtoupper($data['currency']) !== strtoupper($payment->currency)) {
                return [
                    'status' => 'rejected',
                    'reason' => "Currency mismatch: expected {$payment->currency}, received {$data['currency']}",
                ];
            }

            if ($eventType === 'payment.succeeded') {
                if ($payment->status === 'completed') {
                    return [
                        'status' => 'already_processed',
                        'payment_id' => $payment->id,
                    ];
                }

                $payment->update([
                    'status' => 'completed',
                    'notes' => ($payment->notes ? $payment->notes . ' | ' : '') . 'Gateway payment confirmed at ' . now()->toIso8601String(),
                ]);

                // Update session
                if ($payment->session_id) {
                    $session = TableSession::where('id', $payment->session_id)->lockForUpdate()->first();
                    if ($session) {
                        $session->increment('paid_amount', $payment->amount);
                        $remaining = max(0, round($session->total - $session->paid_amount, 2));

                        if ($remaining <= 0) {
                            $session->update([
                                'status' => 'closed',
                                'closed_at' => now(),
                            ]);
                            if ($session->table) {
                                $session->table->update(['status' => 'cleaning']);
                            }
                        }
                    }
                }

                AuditLog::record('gateway_payment_succeeded', $payment, [
                    'payment_id' => $payment->id,
                    'transaction_reference' => $txnId,
                    'amount' => $payment->amount,
                    'event_id' => $eventId,
                ]);

                return [
                    'status' => 'completed',
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                ];
            }

            if ($eventType === 'payment.failed') {
                $payment->update([
                    'status' => 'failed',
                    'notes' => ($payment->notes ? $payment->notes . ' | ' : '') . 'Gateway failure: ' . ($data['failure_reason'] ?? 'Unknown error'),
                ]);

                AuditLog::record('gateway_payment_failed', $payment, [
                    'payment_id' => $payment->id,
                    'transaction_reference' => $txnId,
                    'reason' => $data['failure_reason'] ?? 'Unknown error',
                    'event_id' => $eventId,
                ]);

                return [
                    'status' => 'failed',
                    'payment_id' => $payment->id,
                ];
            }

            return [
                'status' => 'unhandled_event',
                'event' => $eventType,
            ];
        });
    }
}
