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
use App\Http\Requests\Bills\CablePurchaseRequest;
use App\Http\Requests\Bills\CableValidateRequest;
use Illuminate\Http\JsonResponse;

class CableController extends Controller
{
    use PurchasesBills;

    public function __construct(
        private readonly MobileCatalogService $catalog,
        private readonly BillCatalogService $billCatalog,
        private readonly ProviderGateway $gateway,
    ) {
    }

    /**
     * GET /v1/cable/providers
     */
    public function providers(): JsonResponse
    {
        return response()->json($this->catalog->cableProviders());
    }

    /**
     * GET /v1/cable/{slug}/variations
     */
    public function variations(string $slug): JsonResponse
    {
        $result = $this->catalog->cableVariations($slug);

        if ($result === null) {
            return response()->json(['status' => 'failed', 'message' => 'Cable provider not found'], 404);
        }

        return response()->json($result);
    }

    /**
     * POST /v2/cable/validate
     */
    public function validateDecoder(CableValidateRequest $request): JsonResponse
    {
        $productId = (string) $request->input('productId');
        $entry = $this->catalog->cableVariations($productId);

        if ($entry === null) {
            return response()->json(['status' => 'failed', 'message' => 'Cable provider not found']);
        }

        try {
            $result = $this->gateway->validateBill(new BillValidationRequest(
                providerName: $this->billCatalog->resolveProvider(TransactionType::CableTv),
                category: TransactionType::CableTv,
                phoneNumber: (string) $request->input('iucNumber'),
                metadata: ['kuda_bill_item' => $productId],
            ));
        } catch (FinancialException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], $e->httpStatusCode());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Customer validated successfully',
            'data' => ['customerName' => $result->valid ? $result->customerName : ' '],
        ]);
    }

    /**
     * POST /v2/cable/purchase
     */
    public function purchase(CablePurchaseRequest $request): JsonResponse
    {
        $iucNumber = (string) $request->input('iucNumber');
        $variationCode = (string) $request->input('variationCode');

        $plan = $this->catalog->findCablePlan($variationCode);

        if ($plan === null) {
            return response()->json(['status' => 'failed', 'message' => 'This cable plan could not be found.'], 422);
        }

        return $this->dispatchPurchase(
            $request,
            TransactionType::CableTv,
            $iucNumber,
            $plan['amountKobo'],
            ['kuda_bill_item' => $variationCode, 'service_name' => $plan['name']],
            'cable.purchase',
            'Cable',
            includeNestedStatus: true,
        );
    }
}
