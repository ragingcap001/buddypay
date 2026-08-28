<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\FundWallet;
use App\Domain\Payments\Services\FundingService;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\TransactionService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\FundMonnifyWalletRequest;
use App\Http\Support\SyntheticIdempotencyKey;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile contract's Monnify wallet-funding flow: a dynamic, one-time
 * bank account minted per funding attempt (Monnify's "Pay With Bank
 * Transfer" — see MonnifyPaymentProvider), not a persistent per-customer
 * Reserved Account (that product requires the customer's BVN on every
 * creation call, which this contract's flow has no step for collecting).
 *
 * This is a presentation layer over the existing FundingService — the
 * same idempotency/risk/ledger-crediting logic every funding path already
 * uses, reshaped into this contract's route/response shapes. It does not
 * replace the generic POST /v1/wallet/fund (multi-provider); this is the
 * Monnify-specific, contract-exact surface.
 */
class WalletFundingController extends Controller
{
    private const OPEN_STATUSES = ['INITIATED', 'PENDING', 'PROCESSING', 'AMBIGUOUS', 'VERIFYING'];

    public function __construct(
        private readonly FundingService $funding,
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
    ) {
    }

    /**
     * POST /v1/wallet/monnify/fund
     */
    public function fund(FundMonnifyWalletRequest $request): JsonResponse
    {
        $user = $this->authUser($request);
        $amountKobo = (int) $request->input('amount') * 100;

        $command = new FundWallet(
            userId: $user->id,
            amountKobo: $amountKobo,
            idempotencyKey: SyntheticIdempotencyKey::forRequest($user->id, 'wallet.monnify.fund', $request->all()),
            method: 'bank_transfer',
            provider: 'monnify',
        );

        $transaction = $this->funding->execute($command);

        return response()->json([
            'status' => 'success',
            'message' => 'Transfer details generated.',
            'data' => $this->shapeFunding($transaction),
        ]);
    }

    /**
     * GET /v1/wallet/monnify/pending-fund
     */
    public function pendingFund(Request $request): JsonResponse
    {
        $transaction = $this->openFundingQuery($request)->first();

        if ($transaction === null) {
            return response()->json(['status' => 'success', 'message' => 'No pending funding.', 'data' => null]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pending funding fetched.',
            'data' => $this->shapeFunding($transaction),
        ]);
    }

    /**
     * GET /v1/wallet/monnify/fund/{id}/requery — silent background poll.
     */
    public function requery(Request $request, int $id): JsonResponse
    {
        $transaction = $this->findFunding($request, $id);

        if (TransactionStatus::from($transaction->status) !== TransactionStatus::Completed) {
            $transaction = $this->funding->verifyReference($transaction);
        }

        if (TransactionStatus::from($transaction->status) === TransactionStatus::Completed) {
            return response()->json(['status' => 'success', 'message' => 'Payment already confirmed.']);
        }

        return response()->json(['status' => 'pending', 'message' => 'Payment not yet received.']);
    }

    /**
     * POST /v1/wallet/monnify/fund/{id}/confirm-payment — user-initiated
     * "I've paid" force-check. Same underlying verification as requery();
     * only the wording (and the balance in a confirmed response) differs.
     */
    public function confirmPayment(Request $request, int $id): JsonResponse
    {
        $transaction = $this->findFunding($request, $id);

        if (TransactionStatus::from($transaction->status) !== TransactionStatus::Completed) {
            $transaction = $this->funding->verifyReference($transaction);
        }

        if (TransactionStatus::from($transaction->status) === TransactionStatus::Completed) {
            $wallet = $this->wallets->forUser($transaction->user_id);

            return response()->json([
                'status' => 'success',
                'message' => 'This payment was already processed.',
                'data' => ['wallet_balance' => intdiv((int) $wallet->control_balance, 100)],
            ]);
        }

        return response()->json([
            'status' => 'pending',
            'message' => "We haven't received your transfer yet. We'll notify you as soon as it arrives.",
        ]);
    }

    /**
     * POST /v1/wallet/monnify/fund/{id}/retry — expires the current
     * attempt and generates fresh bank details for the same amount.
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $current = $this->findFunding($request, $id);
        $user = $this->authUser($request);

        if (in_array($current->status, self::OPEN_STATUSES, true)) {
            \Illuminate\Support\Facades\DB::transaction(
                fn () => $this->transactions->transition($current, TransactionStatus::Failed, 'superseded_by_retry'),
            );
        }

        $command = new FundWallet(
            userId: $user->id,
            amountKobo: (int) $current->amount,
            idempotencyKey: SyntheticIdempotencyKey::forRequest($user->id, 'wallet.monnify.fund.retry', [
                'previous_reference' => $current->reference,
                'requested_at' => now()->toIso8601String(),
            ]),
            method: 'bank_transfer',
            provider: 'monnify',
        );

        $transaction = $this->funding->execute($command);

        return response()->json([
            'status' => 'success',
            'message' => 'New transfer details generated.',
            'data' => $this->shapeFunding($transaction),
        ]);
    }

    /**
     * GET /v1/wallet/monnify/fundings
     */
    public function fundings(Request $request): JsonResponse
    {
        $paginator = $this->fundingQuery($request)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (Transaction $txn): array => [
                'id' => $txn->id,
                'amount' => intdiv((int) $txn->amount, 100),
                'reference' => $txn->reference,
                'status' => $this->fundingStatusLabel(TransactionStatus::from($txn->status)),
                'payment_provider' => $txn->provider,
                'created_at' => $txn->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Funding history fetched.',
            'data' => $paginator,
        ]);
    }

    /**
     * GET /v1/wallet/monnify/fundings/{id}
     */
    public function fundingDetail(Request $request, int $id): JsonResponse
    {
        $transaction = $this->findFunding($request, $id);
        $details = (array) ($transaction->metadata['payment_details'] ?? []);

        return response()->json([
            'status' => 'success',
            'message' => 'Funding detail fetched.',
            'data' => [
                'id' => $transaction->id,
                'amount' => intdiv((int) $transaction->amount, 100),
                'status' => $this->fundingStatusLabel(TransactionStatus::from($transaction->status)),
                'reference' => $transaction->reference,
                'accounts' => $this->accounts($details),
                'expires_at' => $details['expires_at'] ?? null,
                'account_duration_seconds' => $details['account_duration_seconds'] ?? null,
                'meta' => [
                    'accounts' => $this->accounts($details),
                    'expires_at' => $details['expires_at'] ?? null,
                    'confirmed_at' => $transaction->completed_at?->toIso8601String(),
                    'monnify_transaction_ref' => $transaction->provider_reference,
                    'account_duration_seconds' => $details['account_duration_seconds'] ?? null,
                ],
                'created_at' => $transaction->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeFunding(Transaction $transaction): array
    {
        $details = (array) ($transaction->metadata['payment_details'] ?? []);

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => intdiv((int) $transaction->amount, 100),
            'expires_at' => $details['expires_at'] ?? null,
            'account_duration_seconds' => $details['account_duration_seconds'] ?? null,
            'accounts' => $this->accounts($details),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array<string, mixed>>
     */
    private function accounts(array $details): array
    {
        if (($details['account_number'] ?? '') === '') {
            return [];
        }

        return [[
            'bankName' => $details['bank'] ?? null,
            'bankCode' => $details['bank_code'] ?? null,
            'accountNumber' => $details['account_number'] ?? null,
            'accountName' => $details['account_name'] ?? null,
        ]];
    }

    private function fundingStatusLabel(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::Completed => 'success',
            TransactionStatus::Failed => 'failed',
            default => 'pending',
        };
    }

    private function findFunding(Request $request, int $id): Transaction
    {
        /** @var Transaction */
        return $this->fundingQuery($request)->where('id', $id)->firstOrFail();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function openFundingQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->fundingQuery($request)->whereIn('status', self::OPEN_STATUSES)->orderByDesc('created_at');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function fundingQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::where('user_id', $this->authUser($request)->id)
            ->where('type', TransactionType::WalletFunding->value)
            ->where('provider', 'monnify');
    }

    private function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return $user;
    }
}
