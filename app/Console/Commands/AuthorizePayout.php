<?php

namespace App\Console\Commands;

use App\Domain\Payments\Contracts\OtpAuthorizablePayoutProvider;
use App\Domain\Payments\Services\PayoutService;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Infrastructure\Providers\ProviderFactory;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Authorize a Monnify disbursement that is awaiting MFA
 * (PENDING_AUTHORIZATION) with the OTP sent to the Monnify account's
 * registered email.
 *
 *   php artisan payouts:authorize {platform transaction reference} {otp}
 *
 * The command applies the definitive outcome to the internal transaction
 * (success commits the wallet reservation, failure releases it).
 */
class AuthorizePayout extends Command
{
    protected $signature = 'payouts:authorize
                            {reference : Platform transaction reference (or provider reference)}
                            {otp : OTP from the Monnify account email}';

    protected $description = 'Authorize a payout awaiting MFA (Monnify PENDING_AUTHORIZATION) with the OTP from the account email';

    public function handle(PayoutService $payouts, ProviderFactory $factory): int
    {
        $reference = (string) $this->argument('reference');
        $otp = (string) $this->argument('otp');

        $txn = Transaction::where('reference', $reference)->first();

        if ($txn !== null) {
            if ($txn->type !== TransactionType::BankTransfer->value) {
                $this->error("Transaction [{$reference}] is not a bank transfer payout.");

                return self::FAILURE;
            }

            $status = TransactionStatus::from($txn->status);

            if (! in_array($status, [TransactionStatus::Processing, TransactionStatus::Ambiguous, TransactionStatus::Verifying], true)) {
                $this->error("Transaction [{$reference}] is already settled (status: {$txn->status}).");

                return self::FAILURE;
            }

            $providerName = (string) ($txn->provider ?? config('ase.default_payout_provider', 'wema'));
            $providerReference = (string) ($txn->provider_reference ?? $txn->reference);
        } else {
            $providerName = (string) config('ase.default_payout_provider', 'wema');
            $providerReference = $reference;
        }

        $provider = $factory->makePayoutProvider($providerName);

        if (! $provider instanceof OtpAuthorizablePayoutProvider) {
            $this->error("Provider [{$providerName}] does not support OTP authorization.");

            return self::FAILURE;
        }

        $response = $provider->authorize($providerReference, $otp);

        if ($txn !== null) {
            $payouts->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                $response->outcome,
                $response->providerReference,
                $response->errorMessage,
            );

            $fresh = $txn->fresh();
            $this->info("Payout {$fresh->reference} -> {$fresh->status}");
        } else {
            $this->info('No internal transaction found for the reference — provider outcome: '.$response->errorMessage);
        }

        return $response->outcome === ProviderOutcome::DefinitiveSuccess
            ? self::SUCCESS
            : self::FAILURE;
    }
}
