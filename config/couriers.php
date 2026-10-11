<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Courier coverage list sync (courier hub slice 2)
    |--------------------------------------------------------------------------
    |
    | The city, zone and area lists are read from the courier's API through an
    | active account. Pathao has about 1,300 zones, so a full sync is well over
    | a thousand calls: they are paced, and one job run makes a bounded number
    | before handing over to the next, so a worker restart costs one batch,
    | never the whole sync.
    |
    */

    // Pause between two calls to the courier, in milliseconds.
    'location_sync_delay_ms' => (int) env('COURIER_LOCATION_SYNC_DELAY_MS', 250),

    // Calls one job run makes before it queues the next run.
    'location_sync_calls_per_job' => (int) env('COURIER_LOCATION_SYNC_CALLS_PER_JOB', 150),


    /*
    |--------------------------------------------------------------------------
    | Parcel status pull (courier hub slice 4)
    |--------------------------------------------------------------------------
    |
    | couriers:sync-parcels runs every 15 minutes and asks the courier about
    | each parcel it has not finished with, paced, a bounded number per run.
    |
    */

    'parcel_sync_delay_ms' => (int) env('COURIER_PARCEL_SYNC_DELAY_MS', 300),

    'parcel_sync_limit' => (int) env('COURIER_PARCEL_SYNC_LIMIT', 200),

    // ⚠ Pathao's cancel endpoint is NOT on its documentation page (11 Oct
    // 2026). While on, the ERP tries it and any failure says to cancel in the
    // Pathao panel; switch off to always send people to the panel.
    'pathao_api_cancel' => (bool) env('COURIER_PATHAO_API_CANCEL', true),

];
