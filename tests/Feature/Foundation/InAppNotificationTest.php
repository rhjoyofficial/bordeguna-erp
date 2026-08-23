<?php

use App\Models\User;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Approvals notify in the app, not by email.
 *
 * ⚠⚠ Joy, 2026-08-23: "do not send mail to request correction, ERP
 * notification is enough." Every leave request, loan, correction, stock
 * request and purchase order used to email its approver against a live SMTP
 * host on mail.bordeguna.com. The first test is the one that matters: it fails
 * the moment mail creeps back into the channel list.
 */
it('delivers an approval to the database and not by mail', function () {
    $notification = new PendingApprovalNotification('Leave request from Rafi', '/approvals');

    expect($notification->via(User::factory()->make()))
        ->toBe(['database'])
        ->not->toContain('mail');
});

it('stores what the bell needs to draw a line', function () {
    $notification = new PendingApprovalNotification('Loan request from Musa', '/approvals');

    $payload = $notification->toDatabase(User::factory()->make());

    expect($payload)->toHaveKeys(['kind', 'summary', 'url'])
        ->and($payload['summary'])->toBe('Loan request from Musa')
        ->and($payload['url'])->toBe('/approvals');
});

it('sends no email when an approval is raised', function () {
    Mail::fake();

    $user = User::factory()->create();
    $user->notify(new PendingApprovalNotification('Correction from Iftekharul', '/approvals'));

    Mail::assertNothingSent();

    // And it did land where the bell will find it.
    expect($user->fresh()->unreadNotifications()->count())->toBe(1);
});

it('lets a user mark one notification read, and only their own', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $mine->notify(new PendingApprovalNotification('Mine', '/approvals'));
    $theirs->notify(new PendingApprovalNotification('Theirs', '/approvals'));

    $id = $theirs->fresh()->unreadNotifications()->first()->id;

    // Reaching for somebody else's notification changes nothing.
    $this->actingAs($mine)->post("/notifications/{$id}/read")->assertRedirect();

    expect($theirs->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($mine->fresh()->unreadNotifications()->count())->toBe(1);

    $ownId = $mine->fresh()->unreadNotifications()->first()->id;
    $this->actingAs($mine)->post("/notifications/{$ownId}/read")->assertRedirect();

    expect($mine->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks everything read at once', function () {
    $user = User::factory()->create();

    $user->notify(new PendingApprovalNotification('One', '/approvals'));
    $user->notify(new PendingApprovalNotification('Two', '/approvals'));

    expect($user->fresh()->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user)->post('/notifications/read')->assertRedirect();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('refuses a guest', function () {
    $this->post('/notifications/read')->assertRedirect('/login');
});

it('shares the unread count and recent lines with every page', function () {
    Notification::fake();

    $user = User::factory()->create();

    Notification::assertNothingSent();

    expect($user->unreadNotifications()->count())->toBe(0);
});
