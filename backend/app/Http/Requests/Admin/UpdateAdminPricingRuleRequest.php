<?php

namespace App\Http\Requests\Admin;

/**
 * A rule update is a full replacement (delete + recreate under one atomic
 * transaction - see App\Actions\Admin\Pricing\AdminUpdatePricingRuleAction),
 * never a partial patch, so it accepts exactly the same shape as creating a
 * rule - no separate validation rules to keep in sync.
 */
class UpdateAdminPricingRuleRequest extends CreateAdminPricingRuleRequest {}
