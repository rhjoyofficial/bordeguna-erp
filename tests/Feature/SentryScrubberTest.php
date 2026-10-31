<?php

use App\Support\SentryScrubber;
use Sentry\Event;

/**
 * What Sentry may see. Checked against the rule (no secrets, no money, no
 * contact details) rather than against whatever the scrubber happens to print.
 */
it('redacts secrets, money and contact details at any depth', function () {
    $clean = SentryScrubber::redact([
        'route' => 'payroll.run',
        'salary_amount' => 30000,
        'nested' => ['bank_account_number' => '1071837460002', 'step' => 'calculate'],
        'Authorization' => 'Bearer abc',
        'customer_phone' => '01334943783',
    ]);

    expect($clean['route'])->toBe('payroll.run')
        ->and($clean['salary_amount'])->toBe('[redacted]')
        ->and($clean['nested']['bank_account_number'])->toBe('[redacted]')
        ->and($clean['nested']['step'])->toBe('calculate')
        ->and($clean['Authorization'])->toBe('[redacted]')
        ->and($clean['customer_phone'])->toBe('[redacted]');
});

it('drops the request body, cookies and query string from an event', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://erp.example/hrm/payroll',
        'method' => 'POST',
        'data' => ['net_pay' => 242850],
        'cookies' => ['laravel_session' => 'x'],
        'query_string' => 'email=a@b.c',
    ]);

    $sent = SentryScrubber::beforeSend($event);

    expect($sent->getRequest())->toHaveKey('url')
        ->and($sent->getRequest())->not->toHaveKey('data')
        ->and($sent->getRequest())->not->toHaveKey('cookies')
        ->and($sent->getRequest())->not->toHaveKey('query_string');
});

it('keeps personal data and tracing switched off in config', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and((float) config('sentry.traces_sample_rate'))->toBe(0.0);
});

it('sends nothing when no DSN is configured', function () {
    expect(config('sentry.dsn'))->toBeEmpty();
});
