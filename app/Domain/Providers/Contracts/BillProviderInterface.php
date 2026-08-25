<?php

namespace App\Domain\Providers\Contracts;

use App\Domain\Providers\DTOs\BillPurchaseRequest;
use App\Domain\Providers\DTOs\BillPurchaseResponse;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Providers\DTOs\BillValidationResponse;
use App\Domain\Providers\DTOs\BillVerificationRequest;
use App\Domain\Providers\DTOs\BillVerificationResponse;

/**
 * Contract for bill-service providers (airtime, data, electricity, cable TV,
 * betting, ...). Provider-specific implementation details must stay behind
 * this interface — never in controllers or financial services.
 *
 * Implementations must be idempotent with respect to transactionReference:
 * re-submitting the same reference must not cause a second external charge.
 */
interface BillProviderInterface
{
    public function validateCustomer(BillValidationRequest $request): BillValidationResponse;

    public function purchase(BillPurchaseRequest $request): BillPurchaseResponse;

    public function verify(BillVerificationRequest $request): BillVerificationResponse;
}
