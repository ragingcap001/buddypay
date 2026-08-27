<?php

namespace App\Infrastructure\Providers\Kuda;

use App\Domain\Providers\Contracts\BillProviderInterface;
use App\Domain\Providers\DTOs\BillPurchaseRequest;
use App\Domain\Providers\DTOs\BillPurchaseResponse;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\DTOs\BillValidationResponse;
use App\Domain\Providers\DTOs\BillVerificationRequest;
use App\Domain\Providers\DTOs\BillVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Exceptions\ProviderDeclinedException;
use App\Models\Transaction;
use App\Domain\Transactions\Enums\TransactionType;

/**
 * Kuda (Business API v2.1) bill provider — airtime, internet data and
 * betting (plus any other Kuda catalog category).
 *
 * Flow:
 *   1. The client discovers purchasable bill items via
 *      GET /api/v1/bills/kuda/catalog?category=airtime|data|betting
 *      (Kuda's GET_BILLERS_BY_TYPE) and passes the chosen item's
 *      identifier as `biller` on the pay request (metadata `kuda_bill_item`).
 *   2. `purchase()` submits ADMIN_PURCHASE_BILL with a short Kuda
 *      requestRef (persisted as `kuda_request_ref` metadata) and the
 *      platform amount converted to a whole-Naira string.
 *   3. Kuda's bill responses are almost never final on the wire:
 *      `K00` = received, `K12` = aggregator pending — both map to
 *      AMBIGUOUS. Final status comes from BILL_TSQ (verify) or the
 *      `Bill.Transaction` webhook (which triggers a TSQ).
 */
final class KudaBillProvider implements BillProviderInterface
{
    /**
     * TransactionType value -> Kuda BillTypeName. (String keys: enum cases
     * as constant array keys are not safe across PHP versions.)
     */
    private const BILL_TYPE_NAMES = [
        'AIRTIME' => 'airtime',
        'DATA' => 'internet data',
        'BETTING' => 'betting',
        'ELECTRICITY' => 'electricity',
        'CABLE_TV' => 'cabletv',
    ];

    /**
     * Conservative NG mobile prefix map (first 3 digits after the leading
     * 0/234). Ambiguous prefixes are deliberately omitted — an
     * unknown network declines with BILLER_REQUIRED instead of risking a
     * wrong biller. Verify against the Kuda catalog before production.
     *
     * @var array<string, list<string>>
     */
    private const AIRTIME_NETWORKS = [
        'MTN' => ['803', '806', '813', '814', '816', '903', '906', '913', '916', '700'],
        'AIRTEL' => ['802', '807', '811', '812', '817', '902', '907', '912', '917', '701'],
        'GLO' => ['805', '808', '815', '818', '819', '905', '908', '915', '918', '919'],
        '9MOBILE' => ['809', '804', '707', '709'],
    ];

    public function __construct(private readonly KudaClient $client)
    {
    }

    public function validateCustomer(BillValidationRequest $request): BillValidationResponse
    {
        $billItem = $this->resolveBillItem($request->category, $request->phoneNumber, $request->metadata, requireExplicit: true);

        $payload = $this->client->verifyBillCustomer($billItem, $request->phoneNumber);

        // The documented envelope carries a BOOLEAN `Status`
        // (`{ Status: false, Message: "..." }` on rejection) — a false
        // status is a definitive "customer not found".
        if (KudaClient::findValue($payload, ['status', 'Status']) === false) {
            return new BillValidationResponse(
                false,
                null,
                null,
                (string) (KudaClient::firstString($payload, ['message', 'Message', 'error', 'errorMessage']) ?? 'Kuda could not verify the customer'),
            );
        }

        $status = strtoupper((string) KudaClient::firstString($payload, ['status', 'finalStatus', 'responseCode']));

        if (in_array($status, ['FAILED', 'FAILURE', 'INVALID', 'NOTFOUND', 'NOT_FOUND', 'REJECTED', 'ERROR'], true)) {
            return new BillValidationResponse(
                false,
                null,
                null,
                (string) (KudaClient::firstString($payload, ['message', 'Message', 'error', 'errorMessage']) ?? 'Kuda could not verify the customer'),
            );
        }

        // The documented response nests the name:
        // `{ StatusCode, Status, Message, Data: { CustomerName } }`.
        $name = self::firstStringScopes($payload, ['customerName', 'CustomerName', 'Name', 'name']);

        return new BillValidationResponse(
            true,
            $name,
            null,
            null,
        );
    }

    public function purchase(BillPurchaseRequest $request): BillPurchaseResponse
    {
        $billItem = $this->resolveBillItem($request->category, $request->phoneNumber, $request->metadata, requireExplicit: false);

        $requestRef = $this->client->makeRequestRef('KB');

        $data = [
            'CustomerIdentifier' => (string) ($request->metadata['customer_identifier'] ?? $request->phoneNumber),
            'PhoneNumber' => $request->phoneNumber,
            'BillItemIdentifier' => $billItem,
            'Amount' => KudaClient::toNairaString($request->amount),
        ];

        // CustomerFirstName is not in the public ADMIN_PURCHASE_BILL sample
        // but is accepted by the lenient data bag; only send it when known.
        $customerName = trim((string) ($request->metadata['customer_name'] ?? ''));

        if ($customerName !== '') {
            $data['CustomerFirstName'] = $customerName;
        }

        $payload = $this->client->purchaseBill($data, $requestRef);

        // The documented purchase response is
        // `{ Status, Message, Data: { Reference, Pin } }` — Data.Reference
        // is Kuda's bill response reference (the webhook join key). Flat
        // shapes carry it as BillResponseReference instead.
        $providerReference = self::firstStringScopes($payload, [
            'BillResponseReference',
            'billResponseReference',
            'Reference',
            'reference',
            'ResponseReference',
            'responseReference',
            'BillRequestRef',
            'billRequestRef',
        ]) ?? $requestRef;

        $outcome = $this->classifyBillResponse($payload);

        $message = KudaClient::firstString($payload, ['message', 'Message', 'errorMessage', 'ErrorDescription'])
            ?? $this->describeOutcome($payload, $outcome);

        return new BillPurchaseResponse(
            $outcome,
            $providerReference,
            null,
            $outcome === ProviderOutcome::DefinitiveFailure ? $message : null,
            [
                'kuda_request_ref' => $requestRef,
                'kuda_bill_item' => $billItem,
                'kuda_bill_type' => self::BILL_TYPE_NAMES[$request->category->value] ?? null,
            ],
        );
    }

    public function verify(BillVerificationRequest $request): BillVerificationResponse
    {
        // BILL_TSQ accepts both the bill response reference and our short
        // Kuda requestRef (persisted on the transaction metadata).
        $metadata = Transaction::where('reference', $request->transactionReference)->value('metadata');
        $kudaRequestRef = is_array($metadata) ? (string) ($metadata['kuda_request_ref'] ?? '') : '';

        $payload = $this->client->billTsq($request->providerReference, $kudaRequestRef !== '' ? $kudaRequestRef : null);

        $outcome = $this->classifyBillResponse($payload);

        return new BillVerificationResponse(
            $outcome,
            $request->providerReference,
            $outcome === ProviderOutcome::DefinitiveFailure
                ? (string) (KudaClient::firstString($payload, ['finalStatus', 'message', 'Message']) ?? 'Kuda reported the bill as failed')
                : null,
        );
    }

    /* --------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Map a Kuda bill response/TSQ payload to a platform outcome.
     *
     * Kuda semantics (per the Business API docs):
     *   - K00/k00: request received — NOT final fulfillment.
     *   - K12: aggregator pending — re-query.
     *   - transactionStatus: 3 = completed, 1 = pending.
     *   - finalStatus / hasBeenReversed: terminal descriptors.
     *
     * Status fields may sit at the top level or nested under `Data` (the
     * documented envelope); both scopes are searched. Anything
     * unrecognised — including the documented receipt envelope
     * `{ Status: true, Message: "...", Data: { Reference, Pin } }` — is
     * AMBIGUOUS (verified, never guessed).
     *
     * @param  array<string, mixed>  $payload
     */
    private function classifyBillResponse(array $payload): ProviderOutcome
    {
        $scopes = self::searchScopes($payload);

        foreach ($scopes as $scope) {
            if ((bool) (KudaClient::findValue($scope, ['hasBeenReversed', 'HasBeenReversed']) ?? false)) {
                return ProviderOutcome::DefinitiveFailure;
            }
        }

        foreach ($scopes as $scope) {
            $transactionStatus = KudaClient::findValue($scope, ['transactionStatus', 'TransactionStatus']);

            if (is_numeric($transactionStatus)) {
                return match ((int) $transactionStatus) {
                    3 => ProviderOutcome::DefinitiveSuccess,
                    default => ProviderOutcome::Ambiguous, // 1 = pending, unknown = verify again
                };
            }
        }

        foreach ($scopes as $scope) {
            $finalStatus = strtoupper((string) (KudaClient::firstString($scope, ['finalStatus', 'FinalStatus', 'status', 'Status']) ?? ''));

            if (in_array($finalStatus, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'COMPLETE', 'SUCCESSFULY'], true)) {
                return ProviderOutcome::DefinitiveSuccess;
            }

            if (in_array($finalStatus, ['FAILED', 'FAILURE', 'REJECTED', 'DECLINED', 'CANCELLED', 'CANCELED', 'REVERSED', 'ERROR', 'INVALID'], true)) {
                return ProviderOutcome::DefinitiveFailure;
            }
        }

        // K00 = received, K12 = aggregator pending, PENDING, or unknown.
        return ProviderOutcome::Ambiguous;
    }

    /**
     * First non-empty string among candidate keys, searching the payload
     * top level and then the nested `Data` object (the documented
     * response envelope).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function firstStringScopes(array $payload, array $keys): ?string
    {
        foreach (self::searchScopes($payload) as $scope) {
            $value = KudaClient::firstString($scope, $keys);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The payload itself plus its nested `Data` object (when present) —
     * the scopes Kuda status/reference fields may live in.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function searchScopes(array $payload): array
    {
        $nested = KudaClient::findValue($payload, ['Data', 'data']);

        $scopes = [$payload];

        if (is_array($nested) && $nested !== []) {
            $scopes[] = $nested;
        }

        return $scopes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function describeOutcome(array $payload, ProviderOutcome $outcome): ?string
    {
        $code = self::firstStringScopes($payload, ['responseCode', 'ResponseCode', 'StatusCode', 'statusCode', 'kudaResponseCode', 'billerAggregatorStatus', 'BillerAggregatorStatus']);

        return match ($outcome) {
            ProviderOutcome::DefinitiveSuccess => null,
            ProviderOutcome::DefinitiveFailure => 'Kuda bill failed'.($code !== null ? " ({$code})" : ''),
            default => 'Kuda bill pending'.($code !== null ? " ({$code})" : ''),
        };
    }

    /**
     * Resolve the Kuda bill item identifier for a purchase.
     *
     * Explicit `kuda_bill_item` metadata always wins. For airtime, an
     * unambiguous network prefix resolves against the live catalog.
     * Everything else (data bundles, bookmakers) requires an explicit item.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function resolveBillItem(TransactionType $category, string $phoneNumber, array $metadata, bool $requireExplicit): string
    {
        $explicit = (string) ($metadata['kuda_bill_item'] ?? '');

        if ($explicit !== '') {
            return $explicit;
        }

        if ($requireExplicit) {
            throw new ProviderDeclinedException(
                'BILL_ITEM_REQUIRED',
                'Pass the Kuda bill item identifier (`biller`) — fetch it from GET /api/v1/bills/kuda/catalog.',
                422,
            );
        }

        if ($category === TransactionType::Airtime) {
            $network = $this->detectAirtimeNetwork($phoneNumber);

            if ($network !== null) {
                $item = $this->findCatalogBillItem(self::BILL_TYPE_NAMES[TransactionType::Airtime->value], $network);

                if ($item !== null) {
                    return $item;
                }
            }
        }

        throw new ProviderDeclinedException(
            'BILL_ITEM_REQUIRED',
            'A Kuda bill item is required for this purchase — pass `biller` from GET /api/v1/bills/kuda/catalog.',
            422,
        );
    }

    /**
     * Conservative NG network detection by prefix. Returns null when the
     * prefix is unknown/ambiguous (caller declines rather than guessing).
     */
    private function detectAirtimeNetwork(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Normalise to the 11-digit 0-prefixed form.
        if (str_starts_with($digits, '234')) {
            $digits = '0'.substr($digits, 3);
        }

        $prefix = substr($digits, 1, 3);

        foreach (self::AIRTIME_NETWORKS as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $network;
            }
        }

        return null;
    }

    /**
     * Find a purchasable bill item identifier in the Kuda catalog for a
     * network.
     *
     * Documented catalog shape: a list of billers, each with
     * `BillItems[]` — the identifier (`kudaIdentifier`, e.g.
     * `KD-VTU-MTNNG`) lives on the ITEM, not the biller. A biller matches
     * when its own name or one of its item names contains the network name.
     * A flat list of bill items (no biller wrapper) is also tolerated.
     */
    private function findCatalogBillItem(string $billTypeName, string $network): ?string
    {
        $billers = $this->client->getBillersByType($billTypeName);

        if (! is_array($billers)) {
            return null;
        }

        $normalizedNetwork = (string) preg_replace('/\s+/', '', $network);

        $billers = array_values(array_filter($billers, is_array));

        foreach ($billers as $biller) {
            $billerName = (string) (KudaClient::firstString($biller, ['Name', 'name', 'billerName', 'BillerName', 'Description', 'description']) ?? '');
            $billerMatches = str_contains(self::normalizeName($billerName), $normalizedNetwork);

            $items = KudaClient::findValue($biller, ['billItems', 'BillItems', 'items', 'Items']);

            $candidates = (is_array($items) && $items !== [])
                ? array_values(array_filter($items, is_array))
                : [$biller];

            foreach ($candidates as $candidate) {
                $candidateName = (string) (KudaClient::firstString($candidate, ['Name', 'name', 'Description', 'description']) ?? '');

                if (! $billerMatches && ! str_contains(self::normalizeName($candidateName), $normalizedNetwork)) {
                    continue;
                }

                $identifier = KudaClient::firstString($candidate, [
                    'kudaIdentifier',
                    'KudaIdentifier',
                    'kudaBillItemIdentifier',
                    'KudaBillItemIdentifier',
                    'billItemIdentifier',
                    'BillItemIdentifier',
                    'identifier',
                ]);

                if ($identifier === null) {
                    continue;
                }

                // Reject only UUID-shaped values (biller IDs). Kuda's
                // purchasable item identifiers legitimately contain
                // dashes, e.g. KD-VTU-MTNNG.
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
                    continue;
                }

                return $identifier;
            }
        }

        return null;
    }

    private static function normalizeName(string $name): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($name));
    }
}
