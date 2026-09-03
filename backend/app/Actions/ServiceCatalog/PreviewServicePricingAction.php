<?php

namespace App\Actions\ServiceCatalog;

use App\Support\Cart\CartSelectionValidator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

class PreviewServicePricingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CartSelectionValidator $selectionValidator = new CartSelectionValidator,
        private readonly PricingEngine $pricingEngine = new PricingEngine,
    ) {}

    /**
     * @param  array{options?: array<int, array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function handle(string $slug, array $data): array
    {
        $service = DB::table('services')
            ->where('slug', $slug)
            ->where('is_active', 1)
            ->first(['id']);

        if ($service === null) {
            return $this->notFound('Service not found.');
        }

        $serviceUuid = UuidBinary::toString($service->id);
        $validation = $this->selectionValidator->validate($serviceUuid, $data['options'] ?? []);

        if ($validation['errors'] !== []) {
            return $this->unprocessable('One or more selected options are invalid.', $validation['errors']);
        }

        $pricingPreview = $this->pricingEngine->evaluate(
            serviceIdUuid: $serviceUuid,
            selections: $this->selectionValidator->toPricingSelections($validation['options']),
            quantity: 1,
            currencyCode: DefaultCurrency::code(),
            context: [],
            at: now(),
        );

        return $this->ok(200, 'Pricing preview retrieved successfully.', [
            'pricing_preview' => $pricingPreview->toArray(),
        ]);
    }
}
