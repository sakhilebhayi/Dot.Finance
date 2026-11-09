<?php

use App\Providers\AppServiceProvider;
use App\Providers\BankServiceProvider;
use App\Providers\ControlsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\IntelligenceServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\SharedDataServiceProvider;

return [
    AppServiceProvider::class,
    HealthCheckServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    ControlsServiceProvider::class,
    SharedDataServiceProvider::class,
    IntelligenceServiceProvider::class,
    BankServiceProvider::class,
];
