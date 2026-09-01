<?php

namespace App\Domain\Transactions\Enums;

enum TransactionType: string
{
    case WalletFunding = 'WALLET_FUNDING';
    case BankTransfer = 'BANK_TRANSFER';
    case Airtime = 'AIRTIME';
    case Data = 'DATA';
    case Electricity = 'ELECTRICITY';
    case CableTv = 'CABLE_TV';
    case Betting = 'BETTING';
    case GiftCard = 'GIFT_CARD';
}
