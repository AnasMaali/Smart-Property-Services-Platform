<?php

namespace App\Support\Pricing;

enum ConditionOperator: string
{
    case EQ = 'EQ';
    case NEQ = 'NEQ';
    case GT = 'GT';
    case GTE = 'GTE';
    case LT = 'LT';
    case LTE = 'LTE';
    case IN = 'IN';
    case NOT_IN = 'NOT_IN';
    case BETWEEN = 'BETWEEN';
}
