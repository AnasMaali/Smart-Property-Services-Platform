<?php

namespace App\Support\Pricing;

enum PricingStatus: string
{
    case PRICED = 'PRICED';
    case QUOTE_REQUIRED = 'QUOTE_REQUIRED';
    case MISSING_CONTEXT = 'MISSING_CONTEXT';
    case UNAVAILABLE = 'UNAVAILABLE';
}
