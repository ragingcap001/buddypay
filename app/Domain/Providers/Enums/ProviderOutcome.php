<?php

namespace App\Domain\Providers\Enums;

/**
 * Classification of an external provider response.
 *
 * AMBIGUOUS means the system cannot safely determine whether the provider
 * completed the transaction (timeout, connection reset, unknown response).
 * Ambiguous outcomes must be verified — never blindly failed over — because
 * failing over can cause duplicate external transactions.
 */
enum ProviderOutcome: string
{
    case DefinitiveSuccess = 'DEFINITIVE_SUCCESS';
    case DefinitiveFailure = 'DEFINITIVE_FAILURE';
    case Ambiguous = 'AMBIGUOUS';
}
