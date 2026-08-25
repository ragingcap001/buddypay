<?php

namespace App\Exceptions;

class UnverifiedUserException extends FinancialException
{
    public function __construct()
    {
        parent::__construct(
            'UNVERIFIED_USER',
            'Your phone number has not been verified. Complete OTP verification first.',
            403,
        );
    }
}
