<?php

namespace App\Providers;

use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use App\Support\Contract\Billing\Gateway\FakeContractBillingGateway;
use App\Support\Contract\Billing\Gateway\StripeContractBillingGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The single place ContractBillingGateway is bound - mirrors
 * App\Providers\PaymentServiceProvider exactly, including the same "fake
 * only under testing" guard: FakeContractBillingGateway is bound only under
 * the "testing" environment (see phpunit.xml APP_ENV=testing), so a
 * misconfigured CONTRACT_BILLING_PROVIDER can never accidentally fall back
 * to a fake successful subscription outside of tests. Deliberately its own
 * ServiceProvider, not folded into PaymentServiceProvider, because
 * ContractBillingGateway and PaymentGateway are two fully independent
 * bindings for two fully independent Stripe integrations (BLUE V1 Phase 11
 * "A shared verified transport layer with separate handlers is
 * acceptable" - here even the transport-level provider binding stays
 * separate).
 */
class ContractBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContractBillingGateway::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeContractBillingGateway;
            }

            return match (config('contract_billing.provider')) {
                'stripe' => new StripeContractBillingGateway(
                    config('services.stripe.secret_key'),
                    config('services.stripe.contract_billing_webhook_secret'),
                ),
                default => throw new RuntimeException('Unsupported CONTRACT_BILLING_PROVIDER config value: '.config('contract_billing.provider')),
            };
        });
    }
}
