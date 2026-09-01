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
use App\Http\Requests\Bills\ElectricityPurchaseRequest;
use App\Http\Requests\Bills\ElectricityValidateRequest;
use Illuminate\Http\JsonResponse;

class ElectricityController extends Controller
{
    use PurchasesBills;

    public function __construct(
        private readonly MobileCatalogService $catalog,
        private readonly BillCatalogService $billCatalog,
        private readonly ProviderGateway $gateway,
    ) {
    }

    /**
     * GET /v1/electricity/providers
     */
    public function providers(): JsonResponse
    {
        return response()->json($this->catalog->electricityProviders());
    }

    /**
     * POST /v2/electricity/validate
     */
    public function validateMeter(ElectricityValidateRequest $request): JsonResponse
    {
        $productId = (string) $request->input('productId');
        $type = (string) $request->input('type');
        $billItem = $this->catalog->resolveElectricityBillItem($productId, $type);

        if ($billItem === null) {
            return response()->json(['status' => 'failed', 'message' => 'Electricity provider not found'], 422);
        }

        try {
            $result = $this->gateway->validateBill(new BillValidationRequest(
                providerName: $this->billCatalog->resolveProvider(TransactionType::Electricity),
                category: TransactionType::Electricity,
                phoneNumber: (string) $request->input('meterNumber'),
                metadata: ['kuda_bill_item' => $billItem],
            ));
        } catch (FinancialException $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], $e->httpStatusCode());
        }

        if (! $result->valid) {
            return response()->json([
                'status' => 'success',
                'message' => 'Operation successful!',
                'data' => ['customerName' => ' '],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'Operation successful!',
            'customerName' => $result->customerName,
            'customerAddress' => null,
        ]);
    }

    /**
     * POST /v1/electricity/purchase
     */
    public function purchase(ElectricityPurchaseRequest $request): JsonResponse
    {
        $productId = (string) $request->input('productId');
        $type = (string) $request->input('type');
        $meterNumber = (string) $request->input('meterNumber');
        $amountKobo = (int) round(((float) $request->input('amount')) * 100);

        $billItem = $this->catalog->resolveElectricityBillItem($productId, $type);

        if ($billItem === null) {
            return response()->json(['status' => 'failed', 'message' => 'Electricity provider not found'], 422);
        }

        $serviceName = strtoupper(str_replace('electricity-', '', $productId)).' '.strtoupper($type);

        return $this->dispatchPurchase(
            $request,
            TransactionType::Electricity,
            $meterNumber,
            $amountKobo,
            ['kuda_bill_item' => $billItem, 'service_name' => $serviceName],
            'electricity.purchase',
            'Electricity',
            includeNestedStatus: true,
            // Kuda's electricity token is delivered asynchronously (TSQ /
            // webhook) after the initial purchase response — always
            // present in the contract, populated once that lands.
            extraData: ['token' => null],
        );
    }
}
