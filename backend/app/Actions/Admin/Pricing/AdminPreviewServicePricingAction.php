<?php

namespace App\Actions\Admin\Pricing;

use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\CartSelectionValidator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B23-ext - lets an Admin operator verify a complex pricing
 * configuration BEFORE activating a Service. Selections are validated
 * through the exact SAME App\Support\Cart\CartSelectionValidator the real
 * Cart Add/Update flow uses - never a second, JS-side or otherwise
 * duplicated shape validation - and the calculation itself is delegated to
 * App\Support\Admin\AdminServicePresenter::previewPricing(), the one
 * Support-layer call this codebase's Admin/pricing-engine isolation
 * boundary permits (see Tests\Feature\Admin\AdminFinancialIsolationTest -
 * no class under app/Actions/Admin/** may call the calculation engine
 * directly). No Cart, Cart Item, or any other row is ever written; this is
 * a pure read.
 */
final class AdminPreviewServicePricingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CartSelectionValidator $selectionValidator = new CartSelectionValidator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $optionsInput  Same shape App\Http\Requests\Cart\AddCartItemRequest accepts.
     */
    public function handle(string $serviceUuid, int $quantity, array $optionsInput): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        $service = DB::table('services')->where('id', $serviceIdBinary)->first(['id']);

        if ($service === null) {
            return $this->notFound('Service not found.');
        }

        $validation = $this->selectionValidator->validate($serviceUuid, $optionsInput);

        if ($validation['errors'] !== []) {
            return $this->unprocessable('One or more selected options are invalid.', $validation['errors']);
        }

        $pricing = AdminServicePresenter::previewPricing(
            $serviceUuid,
            $this->selectionValidator->toPricingSelections($validation['options']),
            $quantity,
        );

        return $this->ok(200, 'Pricing preview computed successfully.', ['pricing' => $pricing]);
    }
}
