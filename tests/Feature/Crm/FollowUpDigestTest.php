<?php

use App\Models\User;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\FollowUpsDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The CRM follow-up digest.
 *
 * ⚠⚠ CRM tasks carried a due date from the first day and nothing ever read one.
 * The follow-up queue had to be opened by somebody who already remembered to
 * open it, which is the job a follow-up system exists to take off them.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Chase Co', 'slug' => 'chase-co']);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-chase-co']);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->musa = User::factory()->create(['name' => 'Musa Islam']);
    $this->musa->businesses()->attach($this->business->id);

    $this->lead = Lead::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'phone' => '01711000000',
        'status' => 'new',
    ]);

    Carbon::setTestNow('2026-09-10 08:30:00');

    $this->task = function (string $title, ?string $dueAt, ?User $owner = null, string $status = 'open', ?int $businessId = null) {
        return CrmTask::create([
            'business_id' => $businessId ?? $this->business->id,
            'subject_type' => $this->lead->getMorphClass(),
            'subject_id' => $this->lead->id,
            'title' => $title,
            'due_at' => $dueAt,
            'assigned_to_user_id' => $owner?->id,
            'status' => $status,
            'created_by' => $this->musa->id,
        ]);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('sends one digest a person, not one message a task', function () {
    // ⚠⚠ Ten alerts in a morning are ten things to dismiss, and the eleventh
    // gets dismissed with them.
    Notification::fake();

    ($this->task)('Call about the dates order', '2026-09-10 09:00:00', $this->musa);
    ($this->task)('Send the honey price list', '2026-09-10 11:00:00', $this->musa);
    ($this->task)('Chase the signed challan', '2026-09-08 10:00:00', $this->musa);

    $this->artisan('crm:notify-follow-ups')->assertSuccessful();

    Notification::assertSentToTimes($this->musa, FollowUpsDueNotification::class, 1);

    Notification::assertSentTo($this->musa, function (FollowUpsDueNotification $notification) {
        return $notification->dueToday === 2
            && $notification->overdue === 1
            && str_contains($notification->summary(), '2 follow-ups due today')
            && str_contains($notification->summary(), '1 still open from before');
    });
});

it('keeps chasing a follow-up that was already missed', function () {
    // ⚠ A task missed yesterday is more urgent today, not less. Going quiet
    // after the first mention teaches people to ignore the system.
    Notification::fake();

    ($this->task)('Chase the signed challan', '2026-09-01 10:00:00', $this->musa);

    $this->artisan('crm:notify-follow-ups')->assertSuccessful();

    Notification::assertSentTo($this->musa, fn (FollowUpsDueNotification $n) => $n->overdue === 1 && $n->dueToday === 0);
});

it('says nothing to somebody whose work is not due yet', function () {
    Notification::fake();

    ($this->task)('Visit the Savar dealer', '2026-09-20 10:00:00', $this->musa);

    $this->artisan('crm:notify-follow-ups')->assertSuccessful();

    Notification::assertNothingSent();
});

it('ignores a task that is done, and one nobody owns', function () {
    Notification::fake();

    ($this->task)('Already handled', '2026-09-09 10:00:00', $this->musa, status: 'done');
    // ⚠ Unassigned is nobody's. Telling everybody would make it nobody's twice
    // over; it still stands on the queue screen, which is where it gets picked up.
    ($this->task)('Nobody owns this one', '2026-09-09 10:00:00', null);

    $this->artisan('crm:notify-follow-ups')->assertSuccessful();

    Notification::assertNothingSent();
});

it('never tells one business about another business\'s follow-ups', function () {
    Notification::fake();

    ($this->task)('Rival business work', '2026-09-09 10:00:00', $this->musa, businessId: $this->other->id);

    $this->artisan('crm:notify-follow-ups', ['--business' => $this->business->id])->assertSuccessful();

    Notification::assertNothingSent();
});

it('tells nobody on a dry run', function () {
    Notification::fake();

    ($this->task)('Call about the dates order', '2026-09-10 09:00:00', $this->musa);

    $this->artisan('crm:notify-follow-ups', ['--dry-run' => true])
        ->expectsOutputToContain('Musa Islam')
        ->expectsOutputToContain('Nobody was told')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
