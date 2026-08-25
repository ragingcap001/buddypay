<?php

namespace App\Infrastructure\Providers;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\DTOs\PaymentChargeRequest;
use App\Domain\Payments\DTOs\PaymentChargeResponse;
use App\Domain\Payments\DTOs\PaymentVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Str;

/**
 * Deterministic mock wallet-funding payment provider.
 *
 * mode "success" (default) | "failure" | "timeout" via config('ase.mock.funding_mode').
 */
final class MockPaymentProvider implements PaymentProviderInterface
{
    public function charge(PaymentChargeRequest $request): PaymentChargeResponse
    {
        $mode = (string) config('ase.mock.funding_mode', 'success');

        if ($mode === 'timeout') {
            throw new ProviderTimeoutException('mock');
        }

        if ($mode === 'failure') {
            return new PaymentChargeResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                'Payment provider declined the charge',
            );
        }

        return new PaymentChargeResponse(
            ProviderOutcome::DefinitiveSuccess,
            'MOCKPAY_'.(string) Str::ulid(),
            null,
        );
    }

    public function verify(string $providerReference): PaymentVerificationResponse
    {
        return str_starts_with($providerReference, 'MOCKPAY_')
            ? new PaymentVerificationResponse(ProviderOutcome::DefinitiveSuccess, $providerReference, null)
            : new PaymentVerificationResponse(ProviderOutcome::DefinitiveFailure, null, 'Unknown charge reference');
    }
}
