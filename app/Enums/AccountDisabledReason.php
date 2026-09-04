<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountDisabledReason: string
{
    case MissingFromProvider = 'missing_from_provider';
    case Manual = 'manual';
}
