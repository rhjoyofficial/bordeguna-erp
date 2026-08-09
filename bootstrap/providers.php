<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\Assets\Providers\AssetsServiceProvider;
use App\Modules\Crm\Providers\CrmServiceProvider;
use App\Modules\Foundation\Providers\FoundationServiceProvider;
use App\Modules\Hrm\Providers\HrmServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    AssetsServiceProvider::class,
    CrmServiceProvider::class,
    FoundationServiceProvider::class,
    HrmServiceProvider::class,
    InventoryServiceProvider::class,
    PurchasingServiceProvider::class,
    SalesServiceProvider::class,
];
