<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Correction C12 (2026-07-28): the application runs in Asia/Dhaka, not UTC.
    |
    | ⚠ The DEFAULT is deliberately Asia/Dhaka rather than UTC, so a deployment
    | that forgets APP_TIMEZONE is still correct. Under UTC this system is wrong
    | in three ways that all look like application bugs:
    |
    |  1. ESS clock-in stores now() while the ZKTeco import stores the device's
    |     Dhaka wall-clock. The two sources land SIX HOURS APART in the same
    |     column, so shift comparison, lateness and punch pairing are wrong for
    |     whichever source is used less.
    |  2. Every date boundary — "today", attendance_date, and the 24th→23rd
    |     payroll cutoff — is computed six hours out from the business day.
    |  3. An employee clocking in at 09:41 is told "Recorded at 03:41".
    |
    | Attendance is inherently local wall-clock: a 09:00 shift means 09:00 where
    | the person is standing, not an instant on a global timeline.
    |
    | ⚠ `businesses.timezone` exists and defaults to Asia/Dhaka — it was added
    | for exactly this purpose and nothing reads it. If Bor de Guna ever operates
    | outside Bangladesh, that column is the seam to wire up; until then a single
    | application timezone is correct and far safer than converting at every one
    | of the dozens of date boundaries this system computes.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
