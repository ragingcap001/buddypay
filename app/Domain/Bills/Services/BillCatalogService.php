<?php

namespace App\Domain\Bills\Services;

use App\Domain\Transactions\Enums\TransactionType;
use App\Models\BillProduct;
use App\Models\BillProvider;
use App\Models\Provider;

/**
 * Bill catalog access (categories, providers, products) and provider
 * resolution for a transaction type.
 */
final class BillCatalogService
{
    /**
     * The provider to use for a transaction type: the ACTIVE catalog
     * product's provider when configured, otherwise the default provider.
     */
    public function resolveProvider(TransactionType $type): string
    {
        $product = BillProduct::where('category', $type->value)->where('status', 'ACTIVE')->first();

        if ($product !== null) {
            $billProvider = BillProvider::where('id', $product->bill_provider_id)->where('status', 'ACTIVE')->first();

            if ($billProvider !== null) {
                $provider = Provider::where('id', $billProvider->provider_id)->where('status', 'ACTIVE')->first();

                if ($provider !== null) {
                    return $provider->name;
                }
            }
        }

        return (string) config('ase.default_bill_provider', 'mock');
    }
}
