<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bills\Services\BillCatalogService;
use App\Domain\Bills\Services\MobileCatalogService;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Transactions\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Http\Controllers\Api\V1\Concerns\PurchasesBills;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bills\BettingPurchaseRequest;
use App\Http\Requests\Bills\BettingValidateRequest;
use Illuminate\Http\JsonResponse;

class BettingController extends Controller
{
    use PurchasesBills;

    public function __construct(
        private readonly MobileCatalogService $catalog,
        private readonly BillCatalogService $billCatalog,
        private readonly ProviderGateway $gateway,
    ) {
    }

    /**
     * GET /v1/betting/providers
     */
    public function providers(): JsonResponse
    {
        return response()->json($this->catalog->bettingProviders());
    }

    /**
     * POST /v1/betting/validate
     */
    public function validateCustomer(BettingValidateRequest $request): JsonResponse
    {
        $productId = (string) $request->input('productId');

        try {
            $result = $this->gateway->validateBill(new BillValidationRequest(
                providerName: $this->billCatalog->resolveProvider(TransactionType::Betting),
                category: TransactionType::Betting,
                phoneNumber: (string) $request->input('customerId'),
                metadata: ['kuda_bill_item' => $productId],
            ));
        } catch (FinancialException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], $e->httpStatusCode());
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Operation successful!',
            'data' => [
                'is_valid' => $result->valid,
                'customer_name' => $result->customerName,
            ],
        ]);
    }

    /**
     * POST /v1/betting/fund
     */
    public function fund(BettingPurchaseRequest $request): JsonResponse
    {
        $customerId = (string) $request->input('customerId');
        $productId = (string) $request->input('productId');
        $amountKobo = (int) round(((float) $request->input('amount')) * 100);

        $provider = $this->catalog->findBettingProvider($productId);
        $serviceName = $provider !== null
            ? "{$provider['minAmount']} - {$provider['maxAmount']}NGN FUND {$provider['name']}"
            : 'Betting';

        return $this->dispatchPurchase(
            $request,
            TransactionType::Betting,
            $customerId,
            $amountKobo,
            ['kuda_bill_item' => $productId, 'service_name' => $serviceName],
            'betting.fund',
            'Betting',
            includeNestedStatus: true,
        );
    }
}
