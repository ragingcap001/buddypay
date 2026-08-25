<?php

namespace App\Domain\Providers\Enums;

enum ProviderAttemptStatus: string
{
    case Success = 'SUCCESS';
    case Failure = 'FAILURE';
    case Timeout = 'TIMEOUT';
    case Ambiguous = 'AMBIGUOUS';
}
