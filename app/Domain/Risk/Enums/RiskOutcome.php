<?php

namespace App\Domain\Risk\Enums;

enum RiskOutcome: string
{
    case Allow = 'ALLOW';
    case Challenge = 'CHALLENGE';
    case Review = 'REVIEW';
    case Block = 'BLOCK';
}
