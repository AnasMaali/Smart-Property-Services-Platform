<?php

namespace App\Support\Pricing;

enum ConditionSubjectType: string
{
    case OPTION_CHOICE = 'OPTION_CHOICE';
    case OPTION_NUMERIC_VALUE = 'OPTION_NUMERIC_VALUE';
    case OPTION_BOOLEAN_VALUE = 'OPTION_BOOLEAN_VALUE';
    case ITEM_QUANTITY = 'ITEM_QUANTITY';
    case CONTEXT_ATTRIBUTE = 'CONTEXT_ATTRIBUTE';
}
