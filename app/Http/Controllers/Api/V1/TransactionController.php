<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Services\FundingService;
use App\Domain\Payments\Services\PayoutService;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\BillPaymentService;
use App\Domain\Transactions\Services\TransactionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Http\Support\ApiResponse;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly BillPaymentService $billPayments,
        private readonly FundingService $funding,
        private readonly PayoutService $payouts,
    ) {
    }

    /**
     * GET /api/v1/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $limit = (int) min(max($request->query('limit', 50), 1), 100);

        $transactions = $this->transactions->forUser($user->id, $limit);

        return ApiResponse::success(TransactionResource::collection($transactions));
    }

    /**
     * GET /api/v1/transactions/{reference}
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $request->user('sanctum');

        $transaction = Transaction::where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if ($transaction === null) {
            return ApiResponse::error('TRANSACTION_NOT_FOUND', "Transaction [{$reference}] was not found.", 404, $request);
        }

        return ApiResponse::success(new TransactionResource($transaction));
    }

    /**
     * POST /api/v1/transactions/{reference}/verify
     *
     * Verifies an AMBIGUOUS/VERIFYING transaction against the original
     * provider. Never fails over to another provider.
     */
    public function verify(Request $request, string $reference): JsonResponse
    {
        $user = $request->user('sanctum');

        $transaction = Transaction::where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if ($transaction === null) {
            return ApiResponse::error('TRANSACTION_NOT_FOUND', "Transaction [{$reference}] was not found.", 404, $request);
        }

        if ($transaction->type === TransactionType::WalletFunding->value) {
            $fresh = $this->funding->verifyReference($transaction);
        } elseif ($transaction->type === TransactionType::BankTransfer->value) {
            $fresh = $this->payouts->verifyReference($transaction);
        } else {
            $fresh = $this->billPayments->verify($reference);
        }

        return ApiResponse::success(new TransactionResource($fresh), 'Verification complete');
    }
}
