<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(protected PaymentGatewayService $gatewayService)
    {
    }

    /**
     * Handle incoming payment gateway webhooks with cryptographic HMAC signature verification.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signatureHeader = $request->header('X-Signature') ?? $request->header('Stripe-Signature');

        $tenant = \App\Tenancy\TenantContext::getTenant();
        $tenantSecret = !empty($tenant?->webhook_secret) ? $tenant->webhook_secret : null;

        if (!$this->gatewayService->verifyWebhookSignature($rawPayload, $signatureHeader, $tenantSecret)) {
            return response()->json([
                'error' => 'Invalid webhook signature. Request rejected.',
            ], 401);
        }

        $event = json_decode($rawPayload, true);
        if (!$event) {
            return response()->json([
                'error' => 'Malformed JSON payload.',
            ], 400);
        }

        // M18: Ensure webhook event cannot cross into another tenant's session
        $sessionId = $event['data']['object']['metadata']['session_id'] ?? $event['session_id'] ?? null;
        if ($tenant && $sessionId) {
            $session = \App\Models\TableSession::withoutGlobalScopes()->find($sessionId);
            if ($session && (int) $session->tenant_id !== (int) $tenant->id) {
                return response()->json([
                    'error' => 'Cross-tenant webhook rejected.',
                ], 403);
            }
        }

        $result = $this->gatewayService->processEvent($event);

        return response()->json([
            'status' => 'received',
            'result' => $result,
        ]);
    }
}
