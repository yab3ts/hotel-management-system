<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Payment;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSecurityAndGatewayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_with_valid_signature_completes_payment_and_closes_session(): void
    {
        $tenant = Tenant::create(['name' => 'Café Test', 'slug' => 'test-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'slug' => 'main-' . uniqid(),
            'currency' => 'EUR',
            'tax_rate' => 10.00,
            'service_charge' => 5.00,
        ]);
        $table = RestaurantTable::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_number' => 'T-99',
            'status' => 'occupied',
            'capacity' => 4,
            'qr_token' => 'tok99_' . bin2hex(random_bytes(8)),
        ]);
        $session = TableSession::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'table_id' => $table->id,
            'session_token' => 'sess_' . bin2hex(random_bytes(8)),
            'status' => 'billing',
            'total' => 50.00,
            'paid_amount' => 0.00,
            'opened_at' => now(),
        ]);
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'amount' => 50.00,
            'currency' => 'EUR',
            'payment_method' => 'card',
            'status' => 'pending',
            'transaction_reference' => 'ch_test_123456789',
        ]);

        $gatewayService = app(PaymentGatewayService::class);
        $payload = json_encode([
            'event' => 'payment.succeeded',
            'data' => [
                'transaction_id' => 'ch_test_123456789',
                'amount' => 50.00,
                'currency' => 'EUR',
            ],
        ]);
        $signature = $gatewayService->computeSignature($payload);

        $response = $this->withHeaders([
            'X-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->postJson('/api/webhooks/payment', json_decode($payload, true));

        $response->assertStatus(200);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals(50.00, (float) $session->fresh()->paid_amount);
        $this->assertEquals('closed', $session->fresh()->status);
        $this->assertEquals('cleaning', $table->fresh()->status);
    }

    public function test_forged_or_missing_webhook_signature_is_strictly_rejected(): void
    {
        $payload = [
            'event' => 'payment.succeeded',
            'data' => ['transaction_id' => 'fake_txn_id'],
        ];

        // Missing signature
        $resMissing = $this->postJson('/api/webhooks/payment', $payload);
        $resMissing->assertStatus(401);

        // Forged signature
        $resForged = $this->withHeaders([
            'X-Signature' => 'forged_fake_signature_hash_000000000',
        ])->postJson('/api/webhooks/payment', $payload);
        $resForged->assertStatus(401);
    }

    public function test_tampered_amount_and_duplicate_event_are_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Café GW', 'slug' => 'gw-' . uniqid()]);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'slug' => 'main-' . uniqid(),
            'currency' => 'EUR',
        ]);
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'amount' => 75.00,
            'currency' => 'EUR',
            'payment_method' => 'card',
            'status' => 'pending',
            'transaction_reference' => 'ch_tamper_test',
        ]);

        $gatewayService = app(PaymentGatewayService::class);

        // 1. Attacker attempts to send amount $0.01 for $75.00 charge
        $tamperPayload = json_encode([
            'id' => 'evt_tamper_1',
            'event' => 'payment.succeeded',
            'data' => [
                'transaction_id' => 'ch_tamper_test',
                'amount' => 0.01, // Mismatched!
                'currency' => 'EUR',
            ],
        ]);
        $tamperSig = $gatewayService->computeSignature($tamperPayload);

        $resTamper = $this->withHeaders(['X-Signature' => $tamperSig])
            ->postJson('/api/webhooks/payment', json_decode($tamperPayload, true));

        $resTamper->assertStatus(200);
        $this->assertEquals('rejected', $resTamper->json('result.status'));
        $this->assertEquals('pending', $payment->fresh()->status); // Payment remained unfulfilled

        // 2. Legitimate webhook with event_id
        $validPayload = json_encode([
            'id' => 'evt_legit_999',
            'event' => 'payment.succeeded',
            'data' => [
                'transaction_id' => 'ch_tamper_test',
                'amount' => 75.00,
                'currency' => 'EUR',
            ],
        ]);
        $validSig = $gatewayService->computeSignature($validPayload);

        $resValid = $this->withHeaders(['X-Signature' => $validSig])
            ->postJson('/api/webhooks/payment', json_decode($validPayload, true));
        $resValid->assertStatus(200);
        $this->assertEquals('completed', $resValid->json('result.status'));

        // 3. Replay attack with same event ID is immediately recognized as already processed
        $resReplay = $this->withHeaders(['X-Signature' => $validSig])
            ->postJson('/api/webhooks/payment', json_decode($validPayload, true));
        $resReplay->assertStatus(200);
        $this->assertEquals('already_processed', $resReplay->json('result.status'));
    }
}
