<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\InitiateGiftCardPurchase;
use App\Domain\GiftCards\Services\GiftCardCatalogService;
use App\Domain\GiftCards\Services\GiftCardPurchaseService;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Support\MobileTransactionStatus;
use App\Exceptions\FinancialException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCards\PurchaseGiftCardRequest;
use App\Http\Support\SyntheticIdempotencyKey;
use App\Models\GiftCardRedemption;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function __construct(
        private readonly GiftCardCatalogService $catalog,
        private readonly GiftCardPurchaseService $purchases,
    ) {
    }

    /**
     * GET /v1/giftcard/products
     */
    public function products(Request $request): JsonResponse
    {
        try {
            $products = $this->catalog->products([
                'category_id' => $request->query('category_id'),
                'country_code' => $request->query('country_code'),
            ]);
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        return response()->json(['status' => 'success', 'data' => $products]);
    }

    /**
     * GET /v1/giftcard/products/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $product = $this->catalog->product($id);
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        if ($product === null) {
            return response()->json(['status' => 'failed', 'message' => 'Gift card product not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $product]);
    }

    /**
     * GET /v1/giftcard/categories
     */
    public function categories(Request $request): JsonResponse
    {
        try {
            $categories = $this->catalog->categories($request->query('country_code'));
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        return response()->json(['status' => 'success', 'data' => $categories]);
    }

    /**
     * GET /v1/giftcard/countries
     */
    public function countries(): JsonResponse
    {
        try {
            $countries = $this->catalog->countries();
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        return response()->json(['status' => 'success', 'data' => $countries]);
    }

    /**
     * POST /v1/giftcard/purchase
     */
    public function purchase(PurchaseGiftCardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        $productId = (int) $request->input('gift_card_product_id');
        $denomination = (float) $request->input('denomination');

        try {
            $product = $this->catalog->product($productId);
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        if ($product === null) {
            return response()->json(['status' => 'failed', 'success' => false, 'message' => 'Gift card product not found.'], 404);
        }

        try {
            $price = $this->catalog->priceForPurchase($productId, $denomination);
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        if ($price === null) {
            return response()->json([
                'status' => 'failed',
                'success' => false,
                'message' => 'This denomination is not available for this gift card.',
            ], 422);
        }

        $idempotencyKey = SyntheticIdempotencyKey::forRequest($user->id, 'giftcard.purchase', $request->all());

        $command = new InitiateGiftCardPurchase(
            userId: $user->id,
            productId: $productId,
            denomination: $price['unitPrice'],
            totalKobo: $price['totalKobo'],
            idempotencyKey: $idempotencyKey,
            metadata: [
                'service_name' => $product['product_name'],
                'beneficiary' => (string) $user->phone,
                'product_id' => $productId,
                'brand' => $product['brand'],
                'denomination' => $denomination,
                'recipient_currency' => $product['recipient_currency_code'],
            ],
        );

        try {
            $transaction = $this->purchases->execute($command);
        } catch (FinancialException $e) {
            return $this->providerError($e);
        }

        $status = TransactionStatus::from($transaction->status);

        if ($status === TransactionStatus::Failed) {
            return response()->json([
                'status' => 'failed',
                'success' => false,
                'message' => 'Gift card purchase failed. Your wallet has been refunded.',
            ]);
        }

        $data = [
            'reference' => $transaction->reference,
            'transactionStatus' => MobileTransactionStatus::forDisplay($status),
            'brand' => $product['brand'],
            'productName' => $product['product_name'],
            'denomination' => $denomination,
            'recipientCurrency' => $product['recipient_currency_code'],
        ];

        if ($status === TransactionStatus::Completed) {
            $redemption = GiftCardRedemption::where('transaction_id', $transaction->id)->first();
            $data['redemptionCode'] = $redemption?->card_number;
            $data['pin'] = $redemption?->pin_code;
        }

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => $status === TransactionStatus::Completed
                ? 'Gift card purchased successfully.'
                : "Gift card purchase is being processed. We'll notify you once it's confirmed.",
            'data' => $data,
        ]);
    }

    private function providerError(FinancialException $e): JsonResponse
    {
        return response()->json(['status' => 'failed', 'success' => false, 'message' => $e->getMessage()], $e->httpStatusCode());
    }
}
