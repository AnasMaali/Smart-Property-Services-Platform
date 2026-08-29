<?php

use App\Providers\AppServiceProvider;
use App\Providers\ContractBillingServiceProvider;
use App\Providers\OtpDeliveryServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\TechnicianNotificationServiceProvider;

return [
    AppServiceProvider::class,
    OtpDeliveryServiceProvider::class,
    PaymentServiceProvider::class,
    ContractBillingServiceProvider::class,
    TechnicianNotificationServiceProvider::class,
];
