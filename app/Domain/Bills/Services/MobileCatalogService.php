<?php

namespace App\Domain\Bills\Services;

use App\Infrastructure\Providers\Kuda\KudaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shapes Kuda's live GET_BILLERS_BY_TYPE catalog into the mobile
 * contract's network/provider/plan listings.
 *
 * Kuda's own catalog entries don't carry a stable, URL-safe identifier at
 * the *biller* level (only at the individual bill-item level, e.g.
 * `KUD-DSTV-DCOM-011`) — the contract's `slug`/`productId` values
 * (`data-mtn`, `cable-dstv`, `electricity-ikedc`) are minted here, derived
 * from the biller's name, and resolved back to the real Kuda identifier by
 * re-deriving the same slug from a fresh catalog fetch. Betting is the
 * one category where Kuda's own identifiers (`KUD-BET-BET9-001`) already
 * look exactly like the contract's `productId`/`slug`, so no translation
 * layer is needed there.
 *
 * CAVEAT: the exact field names Kuda uses for a biller's icon URL and
 * min/max purchasable amount are not confirmed against a live sandbox
 * response (same caveat already recorded for the rest of the Kuda
 * integration in DEVELOPMENT.md) — this follows the same lenient,
 * multiple-candidate-key lookup already established in KudaBillProvider,
 * and degrades to a placeholder rather than guessing a wrong number.
 */
final class MobileCatalogService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly KudaClient $client,
        private readonly NetworkDetector $networks,
    ) {
    }

    /**
     * @return array{slug: string, name: string, icon: string}|null
     */
    public function detectAirtimeNetwork(string $phone): ?array
    {
        $network = $this->networks->detect($phone);

        if ($network === null) {
            return null;
        }

        $entry = $this->findBillerByName('airtime', $network);

        if ($entry === null) {
            return null;
        }

        $item = $this->firstPurchasableItem($entry);

        if ($item === null) {
            return null;
        }

        return [
            'slug' => $item['identifier'],
            'name' => $network,
            'icon' => $this->icon($entry),
        ];
    }

    /**
     * @return array{slug: string, name: string, icon: string}|null
     */
    public function detectDataNetwork(string $phone): ?array
    {
        $network = $this->networks->detect($phone);

        if ($network === null) {
            return null;
        }

        $entry = $this->findBillerByName('internet data', $network);

        if ($entry === null) {
            return null;
        }

        return [
            'slug' => $this->slugify('data', $this->name($entry)),
            'name' => $network,
            'icon' => $this->icon($entry),
        ];
    }

    /**
     * @return list<array{name: string, slug: string, icon: string, plans: list<array{variationCode: string, name: string, amount: string}>}>
     */
    public function dataNetworks(): array
    {
        return $this->groupedCatalog('internet data', 'data');
    }

    /**
     * @return array{network: string, slug: string, plans: list<array{vId: string, plan: string, amount: string}>}|null
     */
    public function dataVariations(string $slug): ?array
    {
        $entry = $this->findBySlug('internet data', 'data', $slug);

        if ($entry === null) {
            return null;
        }

        return [
            'network' => $this->name($entry),
            'slug' => $slug,
            'plans' => array_map(
                fn (array $item): array => ['vId' => $item['identifier'], 'plan' => $item['name'], 'amount' => $item['amount']],
                $this->purchasableItems($entry),
            ),
        ];
    }

    /**
     * @return list<array{name: string, slug: string, productId: string, minAmount: string, maxAmount: string, icon: string}>
     */
    public function electricityProviders(): array
    {
        $providers = [];

        foreach ($this->catalog('electricity') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $slug = $this->slugify('electricity', $this->name($entry));

            $providers[] = [
                'name' => $this->name($entry),
                'slug' => $slug,
                'productId' => $slug,
                'minAmount' => (string) $this->amountBound($entry, 'min', 1000),
                'maxAmount' => (string) $this->amountBound($entry, 'max', 100000),
                'icon' => $this->icon($entry),
            ];
        }

        return $providers;
    }

    /**
     * Resolve the contract's {productId, type} pair to a Kuda bill item
     * identifier. `type` (prepaid/postpaid) is matched leniently against
     * each candidate item's name/description, mirroring how airtime
     * network names are matched in KudaBillProvider.
     */
    public function resolveElectricityBillItem(string $productId, string $type): ?string
    {
        $entry = $this->findBySlug('electricity', 'electricity', $productId);

        if ($entry === null) {
            return null;
        }

        $items = $this->purchasableItems($entry);
        $normalizedType = strtoupper($type);

        foreach ($items as $item) {
            if (str_contains(strtoupper($item['name']), $normalizedType)) {
                return $item['identifier'];
            }
        }

        // Only one purchasable item on this biller — no prepaid/postpaid
        // split to disambiguate, so it's the answer regardless of `type`.
        return count($items) === 1 ? $items[0]['identifier'] : null;
    }

    /**
     * @return list<array{name: string, slug: string, productId: string, minAmount: string, maxAmount: string, icon: string}>
     */
    public function bettingProviders(): array
    {
        $providers = [];

        foreach ($this->catalog('betting') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // Betting billers ARE individually purchasable items — Kuda's
            // own identifier already matches the contract's slug/productId
            // shape (KUD-BET-...), so no derived slug is needed here.
            $identifier = (string) (KudaClient::firstString($entry, [
                'kudaIdentifier', 'KudaIdentifier', 'billItemIdentifier', 'BillItemIdentifier', 'identifier',
            ]) ?? '');

            if ($identifier === '') {
                continue;
            }

            $providers[] = [
                'name' => $this->name($entry),
                'slug' => $identifier,
                'productId' => $identifier,
                'minAmount' => (string) $this->amountBound($entry, 'min', 100),
                'maxAmount' => (string) $this->amountBound($entry, 'max', 50000),
                'icon' => $this->icon($entry),
            ];
        }

        return $providers;
    }

    /**
     * A single betting biller by its Kuda identifier (== the contract's
     * productId/slug) — used to build the transaction's display name.
     *
     * @return array{name: string, minAmount: string, maxAmount: string}|null
     */
    public function findBettingProvider(string $productId): ?array
    {
        foreach ($this->bettingProviders() as $provider) {
            if ($provider['productId'] === $productId) {
                return ['name' => $provider['name'], 'minAmount' => $provider['minAmount'], 'maxAmount' => $provider['maxAmount']];
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, slug: string, icon: string, plans: list<array{variationCode: string, name: string, amount: string}>}>
     */
    public function cableProviders(): array
    {
        return $this->groupedCatalog('cabletv', 'cable');
    }

    /**
     * @return array{provider: string, slug: string, plans: list<array{variationCode: string, plan: string, amount: string}>}|null
     */
    public function cableVariations(string $slug): ?array
    {
        $entry = $this->findBySlug('cabletv', 'cable', $slug);

        if ($entry === null) {
            return null;
        }

        return [
            'provider' => $this->name($entry),
            'slug' => $slug,
            'plans' => array_map(
                fn (array $item): array => ['variationCode' => $item['identifier'], 'plan' => $item['name'], 'amount' => $item['amount']],
                $this->purchasableItems($entry),
            ),
        ];
    }

    /**
     * Name + kobo price of a specific data plan — never trust a
     * client-supplied amount for a fixed-price catalog item. Returns
     * null if the variation isn't found (caller must decline the purchase).
     *
     * @return array{name: string, amountKobo: int}|null
     */
    public function findDataPlan(string $variation): ?array
    {
        return $this->findPlan('internet data', $variation);
    }

    /**
     * Name + kobo price of a specific cable plan — same rationale as above.
     *
     * @return array{name: string, amountKobo: int}|null
     */
    public function findCablePlan(string $variationCode): ?array
    {
        return $this->findPlan('cabletv', $variationCode);
    }

    /**
     * Assumes Kuda's catalog reports a plan's price in NAIRA (matching
     * the 2dp strings the contract itself displays, e.g. "750.00") —
     * unconfirmed against a live payload, same caveat as the rest of
     * this class.
     *
     * @return array{name: string, amountKobo: int}|null
     */
    private function findPlan(string $billTypeName, string $identifier): ?array
    {
        foreach ($this->catalog($billTypeName) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($this->purchasableItems($entry) as $item) {
                if ($item['identifier'] === $identifier) {
                    return ['name' => $item['name'], 'amountKobo' => (int) round(((float) $item['amount']) * 100)];
                }
            }
        }

        return null;
    }

    /* --------------------------------------------------------------------
     | Shared shaping
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{name: string, slug: string, icon: string, plans: list<array{variationCode: string, name: string, amount: string}>}>
     */
    private function groupedCatalog(string $billTypeName, string $slugPrefix): array
    {
        $result = [];

        foreach ($this->catalog($billTypeName) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $result[] = [
                'name' => $this->name($entry),
                'slug' => $this->slugify($slugPrefix, $this->name($entry)),
                'icon' => $this->icon($entry),
                'plans' => array_map(
                    fn (array $item): array => ['variationCode' => $item['identifier'], 'name' => $item['name'], 'amount' => $item['amount']],
                    $this->purchasableItems($entry),
                ),
            ];
        }

        return $result;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function catalog(string $billTypeName): array
    {
        return Cache::remember(
            'mobile_catalog:'.$billTypeName,
            self::CACHE_TTL_SECONDS,
            fn (): array => (array) $this->client->getBillersByType($billTypeName),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBillerByName(string $billTypeName, string $name): ?array
    {
        $normalized = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($name));

        foreach ($this->catalog($billTypeName) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryName = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($this->name($entry)));

            if ($entryName !== '' && str_contains($entryName, $normalized)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBySlug(string $billTypeName, string $slugPrefix, string $slug): ?array
    {
        foreach ($this->catalog($billTypeName) as $entry) {
            if (is_array($entry) && $this->slugify($slugPrefix, $this->name($entry)) === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function name(array $entry): string
    {
        return (string) (KudaClient::firstString($entry, ['Name', 'name', 'billerName', 'BillerName', 'Description', 'description']) ?? '');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function icon(array $entry): string
    {
        return (string) (KudaClient::firstString($entry, ['icon', 'Icon', 'iconUrl', 'IconUrl', 'logo', 'Logo', 'imageUrl', 'ImageUrl']) ?? '');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function amountBound(array $entry, string $bound, int $fallback): int
    {
        $keys = $bound === 'min'
            ? ['minAmount', 'MinAmount', 'minimumAmount', 'MinimumAmount']
            : ['maxAmount', 'MaxAmount', 'maximumAmount', 'MaximumAmount'];

        $value = KudaClient::firstString($entry, $keys);

        return $value !== null && is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{identifier: string, name: string, amount: string}|null
     */
    private function firstPurchasableItem(array $entry): ?array
    {
        $items = $this->purchasableItems($entry);

        return $items[0] ?? null;
    }

    /**
     * The individual purchasable bill items under a biller — same
     * `billItems`/`items` lenient lookup KudaBillProvider already uses,
     * filtering out UUID-shaped biller ids the same way.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{identifier: string, name: string, amount: string}>
     */
    private function purchasableItems(array $entry): array
    {
        $raw = KudaClient::findValue($entry, ['billItems', 'BillItems', 'items', 'Items']);
        $candidates = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [$entry];

        $items = [];

        foreach ($candidates as $candidate) {
            $identifier = KudaClient::firstString($candidate, [
                'kudaIdentifier', 'KudaIdentifier', 'kudaBillItemIdentifier', 'KudaBillItemIdentifier',
                'billItemIdentifier', 'BillItemIdentifier', 'identifier',
            ]);

            if ($identifier === null || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
                continue;
            }

            $amountKobo = KudaClient::firstString($candidate, ['amount', 'Amount', 'price', 'Price']);

            $items[] = [
                'identifier' => $identifier,
                'name' => $this->name($candidate),
                'amount' => $amountKobo !== null && is_numeric($amountKobo)
                    ? number_format((float) $amountKobo, 2, '.', '')
                    : '0.00',
            ];
        }

        return $items;
    }

    private function slugify(string $prefix, string $name): string
    {
        return $prefix.'-'.Str::slug($name);
    }
}
