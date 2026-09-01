<?php

namespace App\Domain\GiftCards\Contracts;

use App\Domain\GiftCards\DTOs\GiftCardPurchaseRequest;
use App\Domain\GiftCards\DTOs\GiftCardPurchaseResponse;
use App\Domain\GiftCards\DTOs\GiftCardRedeemCode;

interface GiftCardProviderInterface
{
    public function purchase(GiftCardPurchaseRequest $request): GiftCardPurchaseResponse;

    /**
     * Recover the true outcome of a purchase whose initial response was
     * lost (timeout, connection drop) — looked up by the same reference
     * passed as `customIdentifier` on the original order, not by a
     * provider transaction id we may never have received.
     */
    public function verifyByReference(string $transactionReference): GiftCardPurchaseResponse;

    /**
     * Null when the provider has no redeem code for this reference yet
     * (e.g. the purchase hasn't actually settled) — never guessed.
     */
    public function redeemCode(string $providerReference): ?GiftCardRedeemCode;
}
