<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bills\Services\MobileCatalogService;
use App\Domain\Transactions\Enums\TransactionType;
use App\Http\Controllers\Api\V1\Concerns\PurchasesBills;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bills\DataPurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataController extends Controller
{
    use PurchasesBills;

    public function __construct(private readonly MobileCatalogService $catalog)
    {
    }

    /**
     * GET /v1/detect-data-network
     */
    public function detectNetwork(Request $request): JsonResponse
    {
        $result = $this->catalog->detectDataNetwork((string) $request->query('phone', ''));

        if ($result === null) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Could not detect a network for this phone number.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Network detected successfully.',
            'data' => $result,
        ]);
    }

    /**
     * GET /v2/data/networks
     */
    public function networks(): JsonResponse
    {
        return response()->json($this->catalog->dataNetworks());
    }

    /**
     * GET /v2/data/{slug}/variations
     */
    public function variations(string $slug): JsonResponse
    {
        $result = $this->catalog->dataVariations($slug);

        if ($result === null) {
            return response()->json(['message' => 'Data network not found.'], 404);
        }

        return response()->json($result);
    }

    /**
     * POST /v1/data/purchase
     */
    public function purchase(DataPurchaseRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $variation = (string) $request->input('variation');

        $plan = $this->catalog->findDataPlan($variation);

        if ($plan === null) {
            return response()->json(['status' => 'failed', 'message' => 'This data plan could not be found.'], 422);
        }

        return $this->dispatchPurchase(
            $request,
            TransactionType::Data,
            $phone,
            $plan['amountKobo'],
            ['kuda_bill_item' => $variation, 'service_name' => $plan['name']],
            'data.purchase',
            'Data bundle',
            includeNestedStatus: true,
        );
    }
}
