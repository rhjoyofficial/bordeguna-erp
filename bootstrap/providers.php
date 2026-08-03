<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Accounting\Providers\AccountingServiceProvider::class,
    App\Modules\Foundation\Providers\FoundationServiceProvider::class,
    App\Modules\Hrm\Providers\HrmServiceProvider::class,
    App\Modules\Inventory\Providers\InventoryServiceProvider::class,
    App\Modules\Purchasing\Providers\PurchasingServiceProvider::class,
];
