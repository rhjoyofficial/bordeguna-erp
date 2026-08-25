<?php

use App\Modules\Foundation\Actions\SendPasswordSetupLink;

it('gives staff a full day to open a password link', function () {
    // Joy's decision, 25 Aug 2026. An hour beat two employees in a row.
    expect(config('auth.passwords.users.expire'))->toBe(1440);
});

it('says the lifetime the way a person would say it', function (int $minutes, string $expected) {
    config(['auth.passwords.users.expire' => $minutes]);

    expect(app(SendPasswordSetupLink::class)->lifetimeLabel())->toBe($expected);
})->with([
    // The screens read this label, never the raw number: nobody reads
    // "1440 minutes" and thinks "tomorrow morning".
    [1440, '24 hours'],
    [2880, '2 days'],
    [120, '2 hours'],
    [60, '1 hour'],
    [90, '90 minutes'],
]);
