<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\InitiateBillPayment;
use App\Domain\Bills\Services\BillCatalogService;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\BillPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bills\PayBillRequest;
use App\Http\Requests\Bills\ValidateBillRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Support\ApiResponse;
use App\Models\BillCategory;
use App\Models\BillProduct;
use App\Models\BillProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function __construct(
        private readonly BillCatalogService $catalog,
        private readonly ProviderGateway $gateway,
        private readonly BillPaymentService $billPayments,
    ) {
    }

    /**
     * GET /api/v1/bills/categories
     */
    public function categories(): JsonResponse
    {
        $categories = BillCategory::where('status', 'ACTIVE')
            ->orderBy('display_order')
            ->get()
            ->map(fn (BillCategory $category) => [
                'name' => $category->name,
                'display_name' => $category->display_name,
            ])
            ->values();

        return ApiResponse::success($categories);
    }

    /**
     * GET /api/v1/bills/providers
     */
    public function providers(): JsonResponse
    {
        $providers = BillProvider::with('provider:id,name,display_name')
            ->where('status', 'ACTIVE')
            ->get()
            ->map(fn (BillProvider $billProvider) => [
                'name' => $billProvider->provider?->name,
                'display_name' => $billProvider->display_name,
                'description' => $billProvider->description,
            ])
            ->values();

        return ApiResponse::success($providers);
    }

    /**
     * GET /api/v1/bills/products?category=AIRTIME
     */
    public function products(Request $request): JsonResponse
    {
        $query = BillProduct::with('billProvider:id,display_name')
            ->where('status', 'ACTIVE');

        $category = $request->query('category');

        if (is_string($category) && $category !== '') {
            $query->where('category', strtoupper($category));
        }

        $products = $query->get()
            ->map(fn (BillProduct $product) => [
                'name' => $product->name,
                'code' => $product->code,
                'category' => $product->category,
                'provider' => $product->billProvider?->display_name,
            ])
            ->values();

        return ApiResponse::success($products);
    }

    /**
     * POST /api/v1/bills/validate — validate a customer/product with the
     * provider before paying.
     */
    public function validate(ValidateBillRequest $request): JsonResponse
    {
        $type = TransactionType::from((string) $request->input('type'));
        $providerName = $this->catalog->resolveProvider($type);

        $result = $this->gateway->validateBill(new BillValidationRequest(
            providerName: $providerName,
            category: $type,
            phoneNumber: (string) $request->input('phone'),
        ));

        return ApiResponse::success([
            'valid' => $result->valid,
            'customer_name' => $result->customerName,
            'expected_amount' => $result->expectedAmount,
            'error' => $result->errorMessage,
        ]);
    }

    /**
     * POST /api/v1/bills/pay
     *
     * Idempotent: requires the Idempotency-Key header and the transaction
     * PIN (X-Transaction-Pin header).
     */
    public function pay(PayBillRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $metadata = [];

        if ($request->filled('biller')) {
            $metadata['kuda_bill_item'] = (string) $request->input('biller');
        }

        if ($request->filled('customer_identifier')) {
            $metadata['customer_identifier'] = (string) $request->input('customer_identifier');
        }

        if ($request->filled('customer_name')) {
            $metadata['customer_name'] = (string) $request->input('customer_name');
        }

        $command = new InitiateBillPayment(
            userId: $user->id,
            type: TransactionType::from((string) $request->input('type')),
            amountKobo: (int) $request->input('amount'),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            phoneNumber: (string) $request->input('phone'),
            provider: $request->filled('provider') ? (string) $request->input('provider') : null,
            metadata: $metadata,
        );

        $transaction = $this->billPayments->execute($command);

        return ApiResponse::success(
            new TransactionResource($transaction),
            match ($transaction->status) {
                'COMPLETED' => 'Payment completed',
                'FAILED' => 'Payment failed',
                default => 'Payment initiated. The outcome will be verified.',
            },
        );
    }

    /**
     * GET /api/v1/bills/kuda/catalog?category=airtime|data|betting
     *
     * Live Kuda biller/bill-item catalog for a category (pass-through of
     * Kuda's GET_BILLERS_BY_TYPE). Clients use the returned item
     * identifiers as `biller` on POST /bills/pay (provider "kuda").
     */
    public function kudaCatalog(Request $request): JsonResponse
    {
        $category = strtolower((string) $request->query('category', ''));

        $billTypeName = match ($category) {
            'airtime' => 'airtime',
            'data', 'internet' => 'internet data',
            'betting' => 'betting',
            'electricity' => 'electricity',
            'cabletv', 'cable_tv' => 'cabletv',
            '' => null,
            default => null,
        };

        if ($billTypeName === null) {
            return ApiResponse::error(
                'INVALID_CATEGORY',
                'category must be one of: airtime, data, betting, electricity, cabletv.',
                422,
                $request,
            );
        }

        try {
            $billers = app(\App\Infrastructure\Providers\Kuda\KudaClient::class)
                ->getBillersByType($billTypeName);
        } catch (\App\Exceptions\FinancialException $e) {
            return ApiResponse::error($e->errorCode(), $e->getMessage(), $e->httpStatusCode(), $request);
        }

        return ApiResponse::success([
            'category' => $billTypeName,
            'billers' => $billers,
        ]);
    }
}
