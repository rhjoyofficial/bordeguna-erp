<?php

use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Support\SentryScrubber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

/**
 * Sentry error reporting (31 Oct 2026). ERRORS ONLY, NO PERSONAL DATA.
 *
 * ⚠ Nothing is sent unless SENTRY_LARAVEL_DSN is set in the server's .env, so
 * local copies and the test suite stay silent. The DSN is never committed.
 * ⚠ send_default_pii and tracing stay off on purpose: this ERP holds salaries
 * and bank figures. See App\Support\SentryScrubber for the second net.
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'send_default_pii' => false,

    // Errors only: no performance tracing, no profiling.
    'traces_sample_rate' => 0.0,
    'profiles_sample_rate' => 0.0,

    'before_send' => [SentryScrubber::class, 'beforeSend'],

    // Business refusals with a sentence for the person are not bugs.
    'ignore_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ApprovalAlreadyDecidedException::class,
        JournalException::class,
        PeriodClosedException::class,
        MappingNotFound::class,
    ],

    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'tracing' => [
        'enabled' => false,
    ],
];
