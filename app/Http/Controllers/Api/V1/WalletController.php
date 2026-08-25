<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\FundWallet;
use App\Domain\KYC\Enums\KycTier;
use App\Domain\Payments\Services\FundingService;
use App\Domain\Transactions\Services\TransactionService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\FundWalletRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Http\Support\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly FundingService $funding,
    ) {
    }

    /**
     * GET /api/v1/wallet — wallet dashboard summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $wallet = $this->wallets->forUser($user->id);
        $profile = $user->kycProfile;
        $tier = KycTier::tryFrom((int) ($profile?->tier ?? 0)) ?? KycTier::Unverified;

        return ApiResponse::success([
            'wallet' => new WalletResource($wallet),
            'kyc_tier' => $tier->value,
            'kyc_status' => $profile?->status,
            'limits' => $tier->limits(),
        ]);
    }

    /**
     * GET /api/v1/wallet/balance
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $wallet = $this->wallets->forUser($user->id);

        return ApiResponse::success(new WalletResource($wallet));
    }

    /**
     * GET /api/v1/wallet/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $limit = (int) min(max($request->query('limit', 50), 1), 100);

        $transactions = $this->transactions->forUser($user->id, $limit);

        return ApiResponse::success(TransactionResource::collection($transactions));
    }

    /**
     * POST /api/v1/wallet/fund
     *
     * Idempotent: requires the Idempotency-Key header and the transaction
     * PIN (X-Transaction-Pin header).
     */
    public function fund(FundWalletRequest $request): JsonResponse
    {
        $user = /** @var User */ $request->user('sanctum');

        $command = new FundWallet(
            userId: $user->id,
            amountKobo: (int) $request->input('amount'),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            method: (string) ($request->input('method') ?? 'mock_bank'),
        );

        $transaction = $this->funding->execute($command);

        return ApiResponse::success(
            new TransactionResource($transaction),
            'Wallet funding completed',
        );
    }
}
