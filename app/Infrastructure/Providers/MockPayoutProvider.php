<?php

namespace App\Infrastructure\Providers;

use App\Domain\Payments\Contracts\PayoutProviderInterface;
use App\Domain\Payments\DTOs\PayoutRequest;
use App\Domain\Payments\DTOs\PayoutResponse;
use App\Domain\Payments\DTOs\PayoutVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Str;

/**
 * Deterministic mock payout provider (dev/test).
 *
 * mode "success" (default) | "failure" | "timeout" via config('ase.mock.payout_mode').
 */
final class MockPayoutProvider implements PayoutProviderInterface
{
    public function payout(PayoutRequest $request): PayoutResponse
    {
        $mode = (string) config('ase.mock.payout_mode', 'success');

        if ($mode === 'timeout') {
            throw new ProviderTimeoutException('mock');
        }

        if ($mode === 'failure') {
            return new PayoutResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                'Mock payout provider declined the transfer',
            );
        }

        return new PayoutResponse(
            ProviderOutcome::DefinitiveSuccess,
            'MOCKPAYOUT_'.(string) Str::ulid(),
            null,
        );
    }

    public function verify(string $providerReference): PayoutVerificationResponse
    {
        return str_starts_with($providerReference, 'MOCKPAYOUT_')
            ? new PayoutVerificationResponse(ProviderOutcome::DefinitiveSuccess, $providerReference, null)
            : new PayoutVerificationResponse(ProviderOutcome::DefinitiveFailure, null, 'Unknown payout reference');
    }
}
