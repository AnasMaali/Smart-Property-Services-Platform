<?php

namespace App\Support\Admin;

use App\Support\Contract\Billing\ContractBillingPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Service Contract Billing JSON shape (BLUE V1 Phase B5) -
 * a global, cross-customer view over `service_contract_billings`, distinct
 * from App\Support\Admin\AdminContractPresenter::detail()'s embedded
 * `billing` key (which is reached by Contract, one row at a time).
 * Reuses App\Support\Contract\Billing\ContractBillingPresenter::presentRow()
 * for the exact same admin-safe field mapping rather than duplicating it -
 * see that method's docblock.
 */
final class AdminContractBillingPresenter
{
    /**
     * Batch-loaded Admin Contract Billing list row shape - never issues a
     * query per row. Every row in $rows must already carry
     * `service_contracts.contract_number` and
     * `service_contracts.customer_user_id` alongside the raw
     * `service_contract_billings` columns (see App\Actions\Admin\
     * ContractBilling\AdminListContractBillingsAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $currencyIds = $rows->pluck('currency_id')->unique()->values()->all();

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $currencies = DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->get(['id', 'code', 'symbol', 'minor_unit'])
            ->keyBy(fn ($row) => $row->id);

        $statuses = DB::table('service_contract_billing_statuses')->get(['id', 'code'])->keyBy('id');

        return $rows->map(function (object $row) use ($customers, $currencies, $statuses): array {
            $customer = $customers->get($row->customer_user_id);
            $currency = $currencies->get($row->currency_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'contract' => [
                    'uuid' => UuidBinary::toString($row->service_contract_id),
                    'contract_number' => $row->contract_number,
                ],
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($customer->id),
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
                'status' => $statuses->get($row->status_id)?->code,
                'billing_interval' => $row->billing_interval,
                'recurring_amount' => $row->recurring_amount,
                'currency' => $currency === null ? null : [
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'decimal_places' => (int) $currency->minor_unit,
                ],
                'current_period_end' => $row->current_period_end === null ? null : Carbon::parse($row->current_period_end)->toIso8601String(),
                'past_due_since' => $row->past_due_since === null ? null : Carbon::parse($row->past_due_since)->toIso8601String(),
                'cancel_at' => $row->cancel_at === null ? null : Carbon::parse($row->cancel_at)->toIso8601String(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Full Admin Contract Billing detail shape - $row must carry
     * `service_contracts.contract_number` and
     * `service_contracts.customer_user_id` alongside the raw
     * `service_contract_billings` columns (see App\Actions\Admin\
     * ContractBilling\AdminGetContractBillingAction).
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $row->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

        $contractStatus = DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id')
            ->where('service_contracts.id', $row->service_contract_id)
            ->value('service_contract_statuses.code');

        $webhookEvents = DB::table('service_contract_billing_webhook_events')
            ->join('payment_webhook_event_statuses', 'payment_webhook_event_statuses.id', '=', 'service_contract_billing_webhook_events.status_id')
            ->where('service_contract_billing_webhook_events.service_contract_billing_id', $row->id)
            ->orderByDesc('service_contract_billing_webhook_events.received_at')
            ->limit(20)
            ->get([
                'service_contract_billing_webhook_events.provider_event_id',
                'service_contract_billing_webhook_events.event_type',
                'payment_webhook_event_statuses.code as status_code',
                'service_contract_billing_webhook_events.received_at',
                'service_contract_billing_webhook_events.processed_at',
                'service_contract_billing_webhook_events.last_error_code',
                'service_contract_billing_webhook_events.last_error_message',
            ]);

        return array_merge(ContractBillingPresenter::presentRow($row), [
            'contract' => [
                'uuid' => UuidBinary::toString($row->service_contract_id),
                'contract_number' => $row->contract_number,
                'status' => $contractStatus,
            ],
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
                'email' => $customer->email,
            ],
            'recent_webhook_events' => $webhookEvents->map(fn (object $event): array => [
                'provider_event_id' => $event->provider_event_id,
                'event_type' => $event->event_type,
                'status' => $event->status_code,
                'received_at' => Carbon::parse($event->received_at)->toIso8601String(),
                'processed_at' => $event->processed_at === null ? null : Carbon::parse($event->processed_at)->toIso8601String(),
                'last_error_code' => $event->last_error_code,
                'last_error_message' => $event->last_error_message,
            ])->all(),
        ]);
    }
}
