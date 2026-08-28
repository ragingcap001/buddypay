<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Application\Commands\InitiateBillPayment;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\BillPaymentService;
use App\Domain\Transactions\Support\MobileTransactionStatus;
use App\Http\Support\SyntheticIdempotencyKey;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared purchase orchestration for the airtime/data/electricity/betting
 * /cable controllers: build the command, run it through the existing
 * BillPaymentService unchanged, and shape the mobile contract's
 * {status, message, data} envelope from the resulting transaction.
 *
 * No Idempotency-Key header exists in this contract, so the idempotency
 * key is derived from the request body itself (SyntheticIdempotencyKey) —
 * IdempotencyService's duplicate-submission protection still applies
 * transparently.
 */
trait PurchasesBills
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $extraData  Merged into `data` regardless of outcome (e.g. electricity's `token`, always present).
     */
    private function dispatchPurchase(
        Request $request,
        TransactionType $type,
        string $beneficiary,
        int $amountKobo,
        array $metadata,
        string $routeName,
        string $label,
        bool $includeNestedStatus,
        array $extraData = [],
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user('sanctum');

        $idempotencyKey = SyntheticIdempotencyKey::forRequest($user->id, $routeName, $request->all());

        $command = new InitiateBillPayment(
            userId: $user->id,
            type: $type,
            amountKobo: $amountKobo,
            idempotencyKey: $idempotencyKey,
            phoneNumber: $beneficiary,
            metadata: $metadata + ['beneficiary' => $beneficiary],
        );

        $transaction = app(BillPaymentService::class)->execute($command);

        return $this->purchaseResponse($transaction, $label, $includeNestedStatus, $extraData);
    }

    /**
     * @param  array<string, mixed>  $extraData
     */
    private function purchaseResponse(Transaction $transaction, string $label, bool $includeNestedStatus, array $extraData = []): JsonResponse
    {
        $status = TransactionStatus::from($transaction->status);

        $data = ['reference' => $transaction->reference, ...$extraData];

        if ($includeNestedStatus) {
            $data['status'] = MobileTransactionStatus::forDisplay($status);
        }

        return match ($status) {
            TransactionStatus::Completed => response()->json([
                'status' => 'success',
                'message' => "{$label} purchase successful",
                'data' => $data,
            ]),
            TransactionStatus::Failed => response()->json([
                'status' => 'failed',
                'message' => "{$label} purchase failed",
                'data' => $data,
            ]),
            default => response()->json([
                'status' => 'success',
                'message' => "{$label} purchase is being processed. We'll update you shortly.",
                'data' => $data,
            ]),
        };
    }
}
