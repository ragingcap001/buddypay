<?php

namespace App\Domain\GiftCards\Services;

use App\Infrastructure\Providers\Reloadly\ReloadlyClient;

/**
 * Shapes Reloadly's rich, nested catalog responses into the mobile
 * contract's flat, snake_case shape — a presentation-layer transform,
 * same relationship as MobileCatalogService has to Kuda's raw catalog.
 *
 * PRICING NOTE: the contract's single-product endpoint shows a
 * `rangePricing`/denomination breakdown (reloadlyUsdCost, ngnRate,
 * baseNgnPrice, serviceFee, total). Reloadly does not document a
 * client-side formula for this, and this class does not try to
 * reverse-engineer their internal fee math — `baseNgnPrice` comes from
 * Reloadly's own live GET /fx-rate for RANGE products (authoritative,
 * always current) or from the product's own
 * `fixedRecipientToSenderDenominationsMap` for FIXED products (exact,
 * and avoids an extra live call per denomination). `serviceFee`/`total`
 * are this platform's own markup on top, from config('ase.fees.giftcard')
 * — the same config-driven bps/flat pattern every other transaction type
 * uses, so it can be tuned from one place instead of hardcoded here.
 */
final class GiftCardCatalogService
{
    public function __construct(private readonly ReloadlyClient $client)
    {
    }

    /**
     * @param  array<string, mixed>  $filters  category_id, country_code
     * @return list<array<string, mixed>>
     */
    public function products(array $filters = []): array
    {
        $query = array_filter([
            'size' => 200,
            'page' => 1,
            'productCategoryId' => $filters['category_id'] ?? null,
            'countryCode' => $filters['country_code'] ?? null,
        ], fn ($v): bool => $v !== null);

        $products = $this->client->get('/products', $query);

        return array_map(fn (array $p): array => $this->shapeProduct($p), array_values(array_filter($products, 'is_array')));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function product(int $productId): ?array
    {
        $product = $this->client->get("/products/{$productId}");

        if (! isset($product['productId'])) {
            return null;
        }

        return $this->shapeProduct($product) + ['pricing' => $this->pricing($product)];
    }

    /**
     * @return list<array{category_id: int, category_name: string}>
     */
    public function categories(?string $countryCode = null): array
    {
        if ($countryCode === null || $countryCode === '') {
            return array_map(
                fn (array $c): array => ['category_id' => (int) $c['id'], 'category_name' => (string) $c['name']],
                array_values(array_filter($this->client->get('/product-categories'), 'is_array')),
            );
        }

        // Reloadly's /product-categories has no country filter — derive it
        // from the country's actual product list instead of returning the
        // full unfiltered set under a filtered-looking URL.
        $products = $this->client->get("/countries/{$countryCode}/products");
        $seen = [];

        foreach (array_values(array_filter($products, 'is_array')) as $product) {
            $category = $product['category'] ?? null;

            if (is_array($category) && isset($category['id'])) {
                $seen[(int) $category['id']] = (string) ($category['name'] ?? '');
            }
        }

        $result = [];

        foreach ($seen as $id => $name) {
            $result[] = ['category_id' => $id, 'category_name' => $name];
        }

        return $result;
    }

    /**
     * @return list<array{country_code: string, country_name: string}>
     */
    public function countries(): array
    {
        return array_map(
            fn (array $c): array => ['country_code' => (string) $c['isoName'], 'country_name' => (string) $c['name']],
            array_values(array_filter($this->client->get('/countries'), 'is_array')),
        );
    }

    /**
     * The kobo price to actually charge for a specific denomination —
     * used at purchase time, not just for display. Returns null if the
     * product or denomination can't be resolved (caller must decline).
     *
     * @return array{unitPrice: float, totalKobo: int}|null
     */
    public function priceForPurchase(int $productId, float $denomination): ?array
    {
        $product = $this->client->get("/products/{$productId}");

        if (! isset($product['productId'])) {
            return null;
        }

        $allowed = $product['denominationType'] === 'FIXED'
            ? array_map('floatval', (array) ($product['fixedRecipientDenominations'] ?? []))
            : null;

        if ($allowed !== null && ! in_array($denomination, $allowed, true)) {
            return null; // never trust a client-supplied denomination for a FIXED product
        }

        if ($allowed === null) {
            $min = (float) ($product['minRecipientDenomination'] ?? 0);
            $max = (float) ($product['maxRecipientDenomination'] ?? $product['maxrecipientDenomination'] ?? PHP_FLOAT_MAX);

            if ($denomination < $min || $denomination > $max) {
                return null;
            }
        }

        $baseNgn = $this->resolveBaseNgnPrice($product, $denomination);
        $bpsRate = (int) config('ase.fees.giftcard.bps', 0) / 10000;
        $total = $baseNgn + ($baseNgn * $bpsRate) + ((int) config('ase.fees.giftcard.flat', 0) / 100);

        return ['unitPrice' => $denomination, 'totalKobo' => (int) round($total * 100)];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function shapeProduct(array $product): array
    {
        $brand = (array) ($product['brand'] ?? []);
        $category = (array) ($product['category'] ?? []);
        $country = (array) ($product['country'] ?? []);
        $logos = (array) ($product['logoUrls'] ?? []);

        return [
            'id' => (int) ($product['productId'] ?? 0),
            'product_name' => (string) ($product['productName'] ?? ''),
            'brand' => (string) ($brand['brandName'] ?? $product['productName'] ?? ''),
            'logo_url' => (string) ($logos[0] ?? ''),
            'denomination_type' => (string) ($product['denominationType'] ?? ''),
            'fixed_recipient_denominations' => (array) ($product['fixedRecipientDenominations'] ?? []),
            'min_recipient_denomination' => $this->nullableAmount($product['minRecipientDenomination'] ?? null),
            'max_recipient_denomination' => $this->nullableAmount($product['maxRecipientDenomination'] ?? $product['maxrecipientDenomination'] ?? null),
            'recipient_currency_code' => (string) ($product['recipientCurrencyCode'] ?? ''),
            'country_code' => (string) ($country['isoName'] ?? ''),
            'country_name' => (string) ($country['name'] ?? ''),
            'category_id' => (int) ($category['id'] ?? 0),
            'category_name' => (string) ($category['name'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function pricing(array $product): array
    {
        $bpsRate = (int) config('ase.fees.giftcard.bps', 0) / 10000;

        if (($product['denominationType'] ?? '') === 'RANGE') {
            $min = (float) ($product['minRecipientDenomination'] ?? 0);
            $max = (float) ($product['maxRecipientDenomination'] ?? $product['maxrecipientDenomination'] ?? 0);

            return [
                'min' => $this->priceBreakdown($product, $min, $bpsRate),
                'max' => $this->priceBreakdown($product, $max, $bpsRate),
            ];
        }

        $map = (array) ($product['fixedRecipientToSenderDenominationsMap'] ?? []);

        return array_map(function (array $entry) use ($bpsRate): array {
            $denomination = (float) (array_key_first($entry) ?? 0);
            $baseNgn = (float) ($entry[array_key_first($entry)] ?? 0);

            return $this->formatBreakdown($denomination, $baseNgn, $bpsRate);
        }, array_values(array_filter($map, 'is_array')));
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function priceBreakdown(array $product, float $denomination, float $bpsRate): array
    {
        $baseNgn = $this->resolveBaseNgnPrice($product, $denomination);

        return $this->formatBreakdown($denomination, $baseNgn, $bpsRate);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBreakdown(float $denomination, float $baseNgn, float $bpsRate): array
    {
        $serviceFee = round($baseNgn * $bpsRate, 2);

        return [
            'denomination' => $denomination,
            'reloadlyUsdCost' => $denomination,
            'ngnRate' => $denomination > 0 ? round($baseNgn / $denomination, 8) : 0,
            'baseNgnPrice' => number_format($baseNgn, 2, '.', ','),
            'serviceFee' => number_format($serviceFee, 2, '.', ','),
            'total' => number_format($baseNgn + $serviceFee, 2, '.', ','),
        ];
    }

    /**
     * FIXED products carry an exact precomputed NGN price per denomination
     * (`fixedRecipientToSenderDenominationsMap`) — use it directly rather
     * than an extra live call. RANGE products have no such map, so the
     * price for an arbitrary customer-chosen amount is only knowable via
     * a live FX call.
     *
     * @param  array<string, mixed>  $product
     */
    private function resolveBaseNgnPrice(array $product, float $denomination): float
    {
        if (($product['denominationType'] ?? '') === 'FIXED') {
            foreach ((array) ($product['fixedRecipientToSenderDenominationsMap'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $key = array_key_first($entry);

                if ($key !== null && abs((float) $key - $denomination) < 0.0001) {
                    return (float) $entry[$key];
                }
            }
        }

        $fx = $this->client->get('/fx-rate', [
            'currencyCode' => (string) ($product['recipientCurrencyCode'] ?? ''),
            'amount' => $denomination,
        ]);

        return (float) ($fx['senderAmount'] ?? 0);
    }

    private function nullableAmount(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
