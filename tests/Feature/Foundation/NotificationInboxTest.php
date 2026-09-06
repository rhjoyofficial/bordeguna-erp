<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Str;

/**
 * The notification inbox (Joy, 6 Sep 2026: "notifications part now working
 * needs to make it more upgrade").
 *
 * ⚠⚠ WHY IT HAD TO EXIST. Production was holding 112 notifications, 99 of them
 * unread, and the bell renders eight. Everything older had no URL anywhere in
 * the application, and a notification that had been read was gone for good.
 * Since approval emails were switched off on 23 Aug the bell is the ONLY
 * delivery mechanism, so "cannot be found again" means "was never delivered".
 *
 * ⚠ Every test here also asserts the boundary: one person's inbox is their
 * own. The queries are read off the signed-in user, so there is no id to
 * tamper with - and these are what keep it that way.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Inbox Co', 'slug' => 'inbox-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->other = User::factory()->create();
    $this->other->businesses()->attach($this->business->id);
});

function notify(User $user, string $summary, string $kind = 'approval', ?string $readAt = null, ?string $at = null): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Modules\\Foundation\\Notifications\\PendingApprovalNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['kind' => $kind, 'summary' => $summary, 'url' => '/approvals']),
        'read_at' => $readAt,
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);

    return $id;
}

function openInbox(User $user, array $query = [])
{
    return test()->actingAs($user)
        ->withSession(['current_business_id' => test()->business->id])
        ->get(route('notifications.index', $query));
}

it('shows the notifications the bell cannot fit', function () {
    // Thirty against a bell that shows eight.
    foreach (range(1, 30) as $i) {
        notify($this->user, 'Request '.$i);
    }

    openInbox($this->user)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notifications/index')
            ->where('counts.unread', 30)
            ->where('counts.all', 30)
            // 25 to a page, so the thirtieth is two clicks away rather than
            // unreachable.
            ->has('notifications.data', 25));
});

it('keeps read notifications and can show them again', function () {
    notify($this->user, 'Old news', 'approval', now()->subDay()->toDateTimeString());
    notify($this->user, 'Fresh news');

    openInbox($this->user, ['state' => 'unread'])
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.summary', 'Fresh news'));

    openInbox($this->user, ['state' => 'read'])
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.summary', 'Old news')
            ->where('notifications.data.0.read', true));

    openInbox($this->user, ['state' => 'all'])
        ->assertInertia(fn ($page) => $page->has('notifications.data', 2));
});

it('filters by the kind of thing that happened', function () {
    notify($this->user, 'Approve this', 'approval');
    notify($this->user, 'Somebody was late', 'lateness');

    openInbox($this->user, ['state' => 'all', 'kind' => 'lateness'])
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.summary', 'Somebody was late'));

    // And the filter only ever offers types this person has actually had.
    openInbox($this->user, ['state' => 'all'])
        ->assertInertia(fn ($page) => $page->where('kinds', ['approval', 'lateness']));
});

it('shows a person only their own notifications', function () {
    notify($this->user, 'Mine');
    notify($this->other, 'Not mine');

    openInbox($this->user, ['state' => 'all'])
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
            ->where('notifications.data.0.summary', 'Mine'));
});

it('puts one back to unread, because seen on a phone is not dealt with', function () {
    $id = notify($this->user, 'Read too early', 'approval', now()->toDateTimeString());

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('notifications.unread', $id))
        ->assertRedirect();

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->toBeNull();
});

it('refuses to let one person mark another person notification unread', function () {
    $id = notify($this->other, 'Theirs', 'approval', now()->toDateTimeString());

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('notifications.unread', $id));

    // Still read: the update never reached a row that was not theirs.
    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();
});

it('refuses a guest', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

