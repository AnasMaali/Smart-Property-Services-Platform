<?php

namespace App\Support\Pricing;

enum EffectType: string
{
    case SET_PRICE = 'SET_PRICE';
    case ADD_FIXED = 'ADD_FIXED';
    case ADD_PER_UNIT = 'ADD_PER_UNIT';
    case MULTIPLY = 'MULTIPLY';
    case MIN_TOTAL = 'MIN_TOTAL';
    case MAX_TOTAL = 'MAX_TOTAL';
    case QUOTE_REQUIRED = 'QUOTE_REQUIRED';
}
