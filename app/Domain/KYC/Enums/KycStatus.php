<?php

namespace App\Domain\KYC\Enums;

enum KycStatus: string
{
    case Pending = 'PENDING';
    case Submitted = 'SUBMITTED';
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
    case RequiresReview = 'REQUIRES_REVIEW';
    case Expired = 'EXPIRED';
}
