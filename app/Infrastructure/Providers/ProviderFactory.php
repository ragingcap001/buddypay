<?php

namespace App\Infrastructure\Providers;

use App\Domain\GiftCards\Contracts\GiftCardProviderInterface;
use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\Contracts\PayoutProviderInterface;
use App\Domain\Providers\Contracts\BillProviderInterface;
use App\Domain\Providers\Contracts\ReconciliationProviderInterface;
use App\Exceptions\FinancialException;

/**
 * Resolves provider implementations by name from config. Provider-specific
 * wiring lives here — the rest of the application only sees the contracts.
 */
final class ProviderFactory
{
    public function makeBillProvider(string $name): BillProviderInterface
    {
        $class = config("ase.providers.{$name}");

        if (! is_string($class)) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "No bill provider implementation registered for [{$name}].", 404);
        }

        $instance = app($class);

        if (! $instance instanceof BillProviderInterface) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "Class [{$class}] does not implement BillProviderInterface.", 500);
        }

        return $instance;
    }

    public function makePaymentProvider(string $name): PaymentProviderInterface
    {
        $class = config("ase.payment_providers.{$name}");

        if (! is_string($class)) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "No payment provider implementation registered for [{$name}].", 404);
        }

        $instance = app($class);

        if (! $instance instanceof PaymentProviderInterface) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "Class [{$class}] does not implement PaymentProviderInterface.", 500);
        }

        return $instance;
    }

    public function makePayoutProvider(string $name): PayoutProviderInterface
    {
        $class = config("ase.payout_providers.{$name}");

        if (! is_string($class)) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "No payout provider implementation registered for [{$name}].", 404);
        }

        $instance = app($class);

        if (! $instance instanceof PayoutProviderInterface) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "Class [{$class}] does not implement PayoutProviderInterface.", 500);
        }

        return $instance;
    }

    public function makeGiftCardProvider(string $name): GiftCardProviderInterface
    {
        $class = config("ase.giftcard_providers.{$name}");

        if (! is_string($class)) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "No gift card provider implementation registered for [{$name}].", 404);
        }

        $instance = app($class);

        if (! $instance instanceof GiftCardProviderInterface) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "Class [{$class}] does not implement GiftCardProviderInterface.", 500);
        }

        return $instance;
    }

    public function makeReconciliationProvider(string $name): ReconciliationProviderInterface
    {
        $class = config("ase.providers.{$name}");

        if (! is_string($class)) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "No provider implementation registered for [{$name}].", 404);
        }

        $instance = app($class);

        if (! $instance instanceof ReconciliationProviderInterface) {
            throw new FinancialException('PROVIDER_NOT_FOUND', "Class [{$class}] does not implement ReconciliationProviderInterface.", 500);
        }

        return $instance;
    }
}
