<?php

namespace App\Support\Pricing;

enum TierPricingMode: string
{
    case FLAT = 'FLAT';
    case PER_UNIT = 'PER_UNIT';
}
