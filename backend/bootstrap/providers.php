<?php

use App\Providers\AppServiceProvider;
use App\Providers\OtpDeliveryServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    OtpDeliveryServiceProvider::class,
    PaymentServiceProvider::class,
];
