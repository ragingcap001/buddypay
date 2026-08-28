<?php

namespace App\Domain\GiftCards\DTOs;

final class GiftCardRedeemCode
{
    public function __construct(
        public readonly string $cardNumber,
        public readonly ?string $pinCode,
        public readonly ?string $redemptionUrl,
    ) {
    }
}
