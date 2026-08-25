<?php

namespace App\Domain\Wallet\Enums;

enum WalletReservationStatus: string
{
    case Active = 'ACTIVE';
    case Committed = 'COMMITTED';
    case Released = 'RELEASED';
    case Expired = 'EXPIRED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Committed, self::Released, self::Expired], true);
    }
}
