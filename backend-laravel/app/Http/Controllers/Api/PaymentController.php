<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * List all payments.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with(['session.table', 'guest'])
            ->latest()
            ->paginate($request->input('per_page', 50));

        return response()->json($payments);
    }

    /**
     * Get revenue and settlement statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $totalRevenue = Payment::where('status', 'completed')->where('amount', '>', 0)->sum('amount');
        $refundsTotal = abs(Payment::where('status', 'completed')->where('amount', '<', 0)->sum('amount'));
        $netRevenue = $totalRevenue - $refundsTotal;
        $count = Payment::where('status', 'completed')->count();

        $byMethod = Payment::where('status', 'completed')
            ->selectRaw('payment_method, sum(amount) as total, count(*) as count')
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'total_revenue' => $totalRevenue,
            'net_revenue' => $netRevenue,
            'refunds_total' => $refundsTotal,
            'transactions_count' => $count,
            'by_method' => $byMethod,
        ]);
    }

    /**
     * Issue an immutable refund (creates a new negative payment record).
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        // 1. Cross-Tenant Isolation
        $currentTenant = \App\Tenancy\TenantContext::getTenant();
        if ($currentTenant && $payment->tenant_id !== $currentTenant->id) {
            return response()->json([
                'error' => 'Cross-tenant violation: Cannot refund payments from another organization.',
            ], 403);
        }

        // 2. Branch Isolation
        $branchHeader = $request->header('X-Branch-Id');
        if ($branchHeader && $payment->branch_id && (int) $payment->branch_id !== (int) $branchHeader) {
            return response()->json([
                'error' => 'Cross-branch violation: Cannot refund a payment belonging to a different branch.',
            ], 403);
        }

        // 3. Status and Direction checks
        if ($payment->status !== 'completed' || (float) $payment->amount <= 0) {
            return response()->json([
                'error' => 'Only completed positive payment transactions can be refunded.',
            ], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $request, $payment) {
            // Lock payment for concurrency safety
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            // 4. Calculate existing refunds to prevent double/over refunding
            $alreadyRefundedDirect = abs((float) Payment::where('parent_payment_id', $lockedPayment->id)
                ->where('status', 'completed')
                ->sum('amount'));

            $alreadyRefundedLegacy = abs((float) Payment::where('payment_type', 'refund')
                ->where('notes', 'like', "%Refund for {$lockedPayment->transaction_reference}:%")
                ->where('status', 'completed')
                ->sum('amount'));

            $totalRefundedAlready = max($alreadyRefundedDirect, $alreadyRefundedLegacy);
            $maxRefundable = round((float) $lockedPayment->amount - $totalRefundedAlready, 2);

            if ($maxRefundable <= 0) {
                return response()->json([
                    'error' => "This payment of {$lockedPayment->currency} {$lockedPayment->amount} has already been fully refunded.",
                ], 422);
            }

            $refundAmountPositive = !empty($validated['amount'])
                ? round((float) $validated['amount'], 2)
                : $maxRefundable;

            if ($refundAmountPositive > $maxRefundable) {
                return response()->json([
                    'error' => "Refund amount ({$refundAmountPositive}) exceeds remaining refundable balance ({$maxRefundable}).",
                    'remaining_refundable' => $maxRefundable,
                ], 422);
            }

            $negativeRefundAmount = -abs($refundAmountPositive);

            // 5. Create immutable refund ledger row
            $refund = Payment::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'branch_id' => $lockedPayment->branch_id,
                'session_id' => $lockedPayment->session_id,
                'order_id' => $lockedPayment->order_id,
                'guest_id' => $lockedPayment->guest_id,
                'parent_payment_id' => $lockedPayment->id,
                'amount' => $negativeRefundAmount,
                'currency' => $lockedPayment->currency,
                'payment_method' => $lockedPayment->payment_method,
                'payment_type' => 'refund',
                'status' => 'completed',
                'transaction_reference' => 'ref_' . bin2hex(random_bytes(10)),
                'notes' => 'Refund for ' . $lockedPayment->transaction_reference . ': ' . strip_tags($validated['reason']),
                'recorded_by_user_id' => $request->user()?->id,
            ]);

            // 6. Sync table session paid amount if associated
            if ($lockedPayment->session_id) {
                $session = \App\Models\TableSession::where('id', $lockedPayment->session_id)->lockForUpdate()->first();
                if ($session) {
                    $newPaidAmount = max(0, (float) $session->paid_amount - $refundAmountPositive);
                    $sessionUpdates = ['paid_amount' => $newPaidAmount];

                    // If session was closed, reopen to billing so unpaid balance is acknowledged
                    if ($session->status === 'closed' && $newPaidAmount < (float) $session->total) {
                        $sessionUpdates['status'] = 'billing';
                        $session->table?->update(['status' => 'billing']);
                    }
                    $session->update($sessionUpdates);
                }
            }

            // 7. Audit Logging
            AuditLog::record('payment_refunded', $refund, [
                'original_payment_id' => $lockedPayment->id,
                'original_transaction_ref' => $lockedPayment->transaction_reference,
                'refund_amount' => $negativeRefundAmount,
                'remaining_refundable' => round($maxRefundable - $refundAmountPositive, 2),
                'reason' => strip_tags($validated['reason']),
            ]);

            return response()->json([
                'message' => 'Refund processed successfully.',
                'refund' => $refund,
                'remaining_refundable' => round($maxRefundable - $refundAmountPositive, 2),
            ]);
        });
    }
}
