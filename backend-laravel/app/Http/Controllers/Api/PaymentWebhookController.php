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

        if (!$this->gatewayService->verifyWebhookSignature($rawPayload, $signatureHeader)) {
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

        $result = $this->gatewayService->processEvent($event);

        return response()->json([
            'status' => 'received',
            'result' => $result,
        ]);
    }
}
