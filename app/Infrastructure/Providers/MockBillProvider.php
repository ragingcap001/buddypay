<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\Contracts\BillProviderInterface;
use App\Domain\Providers\Contracts\ReconciliationProviderInterface;
use App\Domain\Providers\DTOs\BillPurchaseRequest;
use App\Domain\Providers\DTOs\BillPurchaseResponse;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\DTOs\BillValidationResponse;
use App\Domain\Providers\DTOs\BillVerificationRequest;
use App\Domain\Providers\DTOs\BillVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Enums\ProviderAttemptStatus;
use App\Exceptions\ProviderTimeoutException;
use App\Models\ProviderAttempt;
use App\Models\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Deterministic mock bill provider for local development and automated
 * tests.
 *
 * Behaviour (all configurable via config('ase.mock')):
 *  - validate: phone numbers ending in 999 are invalid.
 *  - purchase: mode "success" (default) succeeds; "failure" always fails;
 *              "timeout" always times out (=> ambiguous).
 *  - verify:   succeeds when a provider reference is present.
 */
final class MockBillProvider implements BillProviderInterface, ReconciliationProviderInterface
{
    public function validateCustomer(BillValidationRequest $request): BillValidationResponse
    {
        if (str_ends_with($request->phoneNumber, '999')) {
            return new BillValidationResponse(false, null, null, 'Customer number not found');
        }

        // Expected bill amount: ₦1,000.00 (100000 kobo).
        return new BillValidationResponse(true, 'Mock Customer', 100000, null);
    }

    public function purchase(BillPurchaseRequest $request): BillPurchaseResponse
    {
        $mode = (string) config('ase.mock.mode', 'success');

        if ($mode === 'timeout') {
            throw new ProviderTimeoutException('mock');
        }

        if ($mode === 'failure' || str_ends_with($request->phoneNumber, '888')) {
            return new BillPurchaseResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                null,
                'Provider rejected the purchase (INSUFFICIENT_FUNDS)',
            );
        }

        return new BillPurchaseResponse(
            ProviderOutcome::DefinitiveSuccess,
            'MOCK_'.(string) Str::ulid(),
            $request->amount,
            null,
        );
    }

    public function verify(BillVerificationRequest $request): BillVerificationResponse
    {
        if ($request->providerReference !== null) {
            return new BillVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $request->providerReference,
                null,
            );
        }

        return new BillVerificationResponse(
            ProviderOutcome::Ambiguous,
            null,
            'No provider reference available to verify against',
        );
    }

    public function fetchRecords(Carbon $from, Carbon $to): iterable
    {
        $provider = Provider::where('name', 'mock')->first();

        if ($provider === null) {
            return [];
        }

        return ProviderAttempt::where('provider_id', $provider->id)
            ->where('type', 'PURCHASE')
            ->whereIn('status', [ProviderAttemptStatus::Success->value, ProviderAttemptStatus::Failure->value])
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->map(fn (ProviderAttempt $attempt): array => [
                'reference' => (string) ($attempt->transaction?->reference ?? ''),
                'provider_reference' => 'MOCK_'.((string) $attempt->id),
                'amount' => (int) ($attempt->transaction?->amount ?? 0),
                'status' => $attempt->status === ProviderAttemptStatus::Success->value ? 'SUCCESS' : 'FAILURE',
                'timestamp' => $attempt->created_at ?? now(),
            ])
            ->filter(fn (array $record): bool => $record['reference'] !== '')
            ->all();
    }
}
