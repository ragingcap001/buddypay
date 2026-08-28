<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Transactions\Support\MobileTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MobileTransactionResource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile contract's transaction history — scoped to bill-payment
 * types only (airtime/data/electricity/betting/cable). Wallet funding
 * has its own history endpoint (see the "Wallet Top up" section of the
 * contract), so WALLET_FUNDING/BANK_TRANSFER rows are excluded here,
 * matching every example the contract gives for this endpoint.
 */
class UserTransactionController extends Controller
{
    private const BILL_TYPES = ['AIRTIME', 'DATA', 'ELECTRICITY', 'CABLE_TV', 'BETTING'];

    /**
     * GET /v1/user/transactions
     *
     * `limit` present -> a plain capped list (`{"data": [...]}`, no
     * pagination metadata). `limit` absent -> a standard 20-per-page
     * paginated listing. This asymmetry is exactly what the contract's
     * own examples show.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::where('user_id', $this->authUser($request)->id)
            ->whereIn('type', self::BILL_TYPES);

        $type = $request->query('type');

        if (is_string($type) && $type !== '') {
            $mapped = MobileTransactionType::fromQueryValue($type);

            if ($mapped !== null) {
                $query->where('type', $mapped->value);
            }
        }

        $query->orderBy('created_at', strtolower((string) $request->query('sort', 'desc')) === 'asc' ? 'asc' : 'desc');

        $limit = $request->query('limit');

        if ($limit !== null && is_numeric($limit)) {
            $transactions = $query->limit(max(1, (int) $limit))->get();

            return response()->json(['data' => MobileTransactionResource::collection($transactions)]);
        }

        return MobileTransactionResource::collection($query->paginate(20))->response();
    }

    /**
     * GET /v1/user/transactions/{transId}
     */
    public function show(Request $request, string $transId): JsonResponse
    {
        $transaction = Transaction::where('user_id', $this->authUser($request)->id)
            ->where('reference', $transId)
            ->first();

        if ($transaction === null) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json(['data' => new MobileTransactionResource($transaction)]);
    }

    private function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return $user;
    }
}
