<?php

namespace App\Support\Pricing;

enum TierCalculationMode: string
{
    case VOLUME = 'VOLUME';
    case GRADUATED = 'GRADUATED';
}
