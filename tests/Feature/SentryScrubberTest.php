<?php

use App\Support\SentryScrubber;
use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
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

it('keeps only the SQLSTATE code of a database error, never the bound values', function () {
    $msg = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'joy@example.com' for key 'users_email' (Connection: mysql, SQL: insert into users (email, salary) values (joy@example.com, 30000))";

    $clean = SentryScrubber::scrubMessage(QueryException::class, $msg);

    expect($clean)->toBe('Database error (SQLSTATE 23000)')
        ->and($clean)->not->toContain('joy@example.com')
        ->and($clean)->not->toContain('30000');
});

it('masks e-mail addresses and long digit runs in any other error message', function () {
    $clean = SentryScrubber::scrubMessage(RuntimeException::class, 'Cannot pay joy@example.com on account 1071837460002 or call 01334943783 for step 4');

    expect($clean)->toBe('Cannot pay [email] on account [number] or call [number] for step 4');
});

it('does not send breadcrumbs, which can carry log text', function () {
    $event = Event::createEvent();
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Paid BDT 242,850 to Musa')]);

    expect(SentryScrubber::beforeSend($event)->getBreadcrumbs())->toBe([]);
});
