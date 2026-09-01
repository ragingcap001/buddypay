<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Bills\Services\MobileCatalogService;
use App\Domain\Transactions\Enums\TransactionType;
use App\Http\Controllers\Api\V1\Concerns\PurchasesBills;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bills\AirtimePurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AirtimeController extends Controller
{
    use PurchasesBills;

    public function __construct(private readonly MobileCatalogService $catalog)
    {
    }

    /**
     * GET /v1/detect-network
     */
    public function detectNetwork(Request $request): JsonResponse
    {
        $result = $this->catalog->detectAirtimeNetwork((string) $request->query('phone', ''));

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
     * POST /v1/airtime/purchase
     */
    public function purchase(AirtimePurchaseRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $network = (string) $request->input('network');
        $amountKobo = (int) round(((float) $request->input('amount')) * 100);

        $detected = $this->catalog->detectAirtimeNetwork($phone);
        $serviceName = ($detected['name'] ?? 'Airtime').' VTU';

        return $this->dispatchPurchase(
            $request,
            TransactionType::Airtime,
            $phone,
            $amountKobo,
            ['kuda_bill_item' => $network, 'service_name' => $serviceName],
            'airtime.purchase',
            'Airtime',
            includeNestedStatus: false,
        );
    }
}
