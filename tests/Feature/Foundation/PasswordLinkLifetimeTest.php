<?php

use App\Models\User;
use App\Modules\Foundation\Actions\SendPasswordSetupLink;
use App\Modules\Foundation\Notifications\SetPasswordNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('gives two days to open a password link', function () {
    // Joy's decisions: a day on 25 Aug 2026 (an hour beat two employees in a
    // row), then "minimum 48hrs" on 21 Sep 2026 for an outside accountant.
    expect(config('auth.passwords.users.expire'))->toBe(2880);
});

it('invites in its own words, with when the link stops working', function () {
    Notification::fake();
    Carbon::setTestNow('2026-09-21 18:00:00');
    $user = User::factory()->create(['name' => 'Mesbah', 'email' => 'mesbah@example.test']);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, SetPasswordNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        return $mail->subject === 'Set your password for the Bor de Guna ERP'
            && str_contains($text, 'This link works for 2 days, until 23 Sep 2026, 6:00 PM (Dhaka time).')
            && str_contains($text, 'Your login is this email address: mesbah@example.test.')
            && ! str_contains($text, 'reset request');
    });

    Carbon::setTestNow();
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
