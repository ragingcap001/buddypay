<?php

namespace App\Domain\Providers\Services;

use App\Domain\Payments\DTOs\PaymentChargeRequest;
use App\Domain\Payments\DTOs\PaymentChargeResponse;
use App\Domain\Payments\DTOs\PaymentVerificationResponse;
use App\Domain\Providers\DTOs\BillPurchaseRequest;
use App\Domain\Providers\DTOs\BillPurchaseResponse;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\DTOs\BillValidationResponse;
use App\Domain\Providers\DTOs\BillVerificationRequest;
use App\Domain\Providers\DTOs\BillVerificationResponse;
use App\Domain\Providers\Enums\ProviderAttemptStatus;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Transactions\Enums\TransactionType;
use App\Exceptions\CircuitOpenException;
use App\Infrastructure\Providers\ProviderFactory;
use App\Models\Provider;
use App\Models\ProviderAttempt;
use App\Models\Transaction;
use Throwable;

/**
 * The single funnel through which all external provider calls pass.
 *
 * Responsibilities:
 *  - enforce provider status and the circuit breaker,
 *  - record a provider_attempts row for every call (audit trail),
 *  - classify the outcome (success / failure / ambiguous),
 *  - feed the circuit breaker.
 *
 * Ambiguous outcomes never trigger automatic failover to another provider.
 */
final class ProviderGateway
{
    public function __construct(
        private readonly ProviderFactory $factory,
        private readonly OutcomeClassifier $classifier,
        private readonly CircuitBreaker $circuitBreaker,
    ) {
    }

    private function providerModel(string $name): Provider
    {
        $provider = Provider::where('name', $name)->first();

        if ($provider === null) {
            throw new \App\Exceptions\FinancialException('PROVIDER_NOT_FOUND', "Provider [{$name}] is not registered.", 404);
        }

        if ($provider->status !== 'ACTIVE') {
            throw new CircuitOpenException($name);
        }

        return $provider;
    }

    public function validateBill(BillValidationRequest $request): BillValidationResponse
    {
        $this->providerModel($request->providerName);
        $provider = $this->factory->makeBillProvider($request->providerName);

        return $provider->validateCustomer($request);
    }

    public function purchaseBill(
        string $providerName,
        TransactionType $category,
        string $phoneNumber,
        int $amount,
        string $transactionReference,
        ?Transaction $transaction = null,
        array $metadata = [],
    ): BillPurchaseResponse {
        $provider = $this->providerModel($providerName);

        if (! $this->circuitBreaker->allowRequest($providerName)) {
            throw new CircuitOpenException($providerName);
        }

        $startedAt = microtime(true);
        $outcome = ProviderOutcome::Ambiguous;
        $response = null;
        $error = null;

        try {
            $billProvider = $this->factory->makeBillProvider($providerName);

            $response = $billProvider->purchase(new BillPurchaseRequest(
                $providerName,
                $category,
                $phoneNumber,
                $amount,
                $transactionReference,
                $metadata,
            ));

            $outcome = $response->outcome;
        } catch (Throwable $e) {
            $outcome = $this->classifier->classifyException($e);
            $error = $e->getMessage();
        }

        $this->recordAttempt($provider, $transaction, 'PURCHASE', $outcome, $error, (int) ((microtime(true) - $startedAt) * 1000));
        $this->updateCircuit($providerName, $outcome);

        if ($response === null) {
            return new BillPurchaseResponse($outcome, null, null, $error ?? 'Provider call failed without a response');
        }

        return $response;
    }

    public function verifyBill(BillVerificationRequest $request): BillVerificationResponse
    {
        $this->providerModel($request->providerName);
        $provider = $this->factory->makeBillProvider($request->providerName);

        return $provider->verify($request);
    }

    public function charge(string $providerName, PaymentChargeRequest $request, ?Transaction $transaction = null): PaymentChargeResponse
    {
        $provider = $this->providerModel($providerName);

        if (! $this->circuitBreaker->allowRequest($providerName)) {
            throw new CircuitOpenException($providerName);
        }

        $startedAt = microtime(true);
        $outcome = ProviderOutcome::Ambiguous;
        $response = null;
        $error = null;

        try {
            $paymentProvider = $this->factory->makePaymentProvider($providerName);

            $response = $paymentProvider->charge($request);
            $outcome = $response->outcome;
        } catch (Throwable $e) {
            $outcome = $this->classifier->classifyException($e);
            $error = $e->getMessage();
        }

        $this->recordAttempt($provider, $transaction, 'CHARGE', $outcome, $error, (int) ((microtime(true) - $startedAt) * 1000));
        $this->updateCircuit($providerName, $outcome);

        if ($response === null) {
            return new PaymentChargeResponse($outcome, null, $error ?? 'Provider call failed without a response');
        }

        return $response;
    }

    public function verifyCharge(string $providerName, string $providerReference): PaymentVerificationResponse
    {
        $this->providerModel($providerName);
        $provider = $this->factory->makePaymentProvider($providerName);

        return $provider->verify($providerReference);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordAttempt(
        Provider $provider,
        ?Transaction $transaction,
        string $type,
        ProviderOutcome $outcome,
        ?string $error,
        int $durationMs,
        array $context = [],
    ): void {
        ProviderAttempt::create([
            'provider_id' => $provider->id,
            'transaction_id' => $transaction?->id,
            'type' => $type,
            'status' => match ($outcome) {
                ProviderOutcome::DefinitiveSuccess => ProviderAttemptStatus::Success->value,
                ProviderOutcome::DefinitiveFailure => ProviderAttemptStatus::Failure->value,
                ProviderOutcome::Ambiguous => ProviderAttemptStatus::Ambiguous->value,
            },
            'duration_ms' => $durationMs,
            'error' => $error,
            'metadata' => $context,
        ]);
    }

    private function updateCircuit(string $providerName, ProviderOutcome $outcome): void
    {
        match ($outcome) {
            ProviderOutcome::DefinitiveSuccess => $this->circuitBreaker->recordSuccess($providerName),
            ProviderOutcome::DefinitiveFailure => $this->circuitBreaker->recordFailure($providerName),
            ProviderOutcome::Ambiguous => null,
        };
    }
}
