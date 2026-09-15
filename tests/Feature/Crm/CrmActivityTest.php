<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM Phase B: what happened, what is due, and whose queue it is.
 *
 * ⚠⚠ THE CENTRAL DESIGN CLAIM IS THAT HISTORY IS IMMUTABLE AND TASKS ARE NOT.
 * Most of what is tested here is that claim holding under pressure, not that a
 * row can be inserted. CRM-PHASE-B-ARCHITECTURE.md, C-CRMB-1..13.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Crm Co', 'slug' => 'crm-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-crm-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    /**
     * ⚠⚠ RETURNS THE USER, NOT A TEST INSTANCE. `actingAs()` returns `$this`
     * and mutates the shared authentication state, so storing its result and
     * reusing it later silently acts as whoever authenticated MOST RECENTLY.
     * An earlier version of this file did exactly that and a Manager assertion
     * ran as Staff. Callers write `$this->actingAs($user)` at the point of use,
     * where the intent is visible.
     */
    $this->asRole = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $this->lead = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01712345678', 'status' => 'new',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── C-CRMB-1: history is append-only ────────────────────────────────────────

it('records an activity through the screen', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'call',
        'direction' => 'outbound',
        'summary' => 'Wants 5kg, will confirm Thursday',
        'duration_seconds' => 180,
    ])->assertRedirect();

    $activity = CrmActivity::sole();

    expect($activity->type)->toBe(ActivityType::Call)
        ->and($activity->summary)->toBe('Wants 5kg, will confirm Thursday')
        ->and($activity->duration_seconds)->toBe(180)
        ->and($activity->subject_id)->toBe($this->lead->id)
        ->and($activity->recorded_by)->toBe($this->owner->id);
});

it('refuses to let a recorded activity be edited or deleted', function () {
    $activity = CrmActivity::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'type' => ActivityType::Note, 'occurred_at' => now(), 'summary' => 'Said yes',
    ]);

    // ⚠⚠ A note of what somebody said on a call is EVIDENCE. Editing it later
    // is how a CRM stops being worth trusting, and by then nobody can tell
    // which entries were changed.
    expect(fn () => $activity->update(['summary' => 'Said no']))
        ->toThrow(RuntimeException::class, 'cannot be edited');

    expect(fn () => $activity->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');

    expect(CrmActivity::sole()->summary)->toBe('Said yes');
});

it('keeps when it HAPPENED separate from when it was typed', function () {
    // ⚠⚠ C-CRMB-2. A call made this morning and written up tonight happened
    // this morning. A report using created_at answers "when did we contact
    // them" wrongly, and wrongly in a way nobody notices.
    Carbon::setTestNow('2026-08-09 22:00:00');

    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'call', 'direction' => 'outbound',
        'occurred_at' => '2026-08-09 09:15:00',
        'summary' => 'Morning call',
    ])->assertRedirect();

    $activity = CrmActivity::sole();

    expect($activity->occurred_at->toDateTimeString())->toBe('2026-08-09 09:15:00')
        ->and($activity->created_at->toDateTimeString())->toBe('2026-08-09 22:00:00');
});

it('refuses an activity dated in the future', function () {
    // ⚠ C-CRMB-3. Something that has not happened is a follow-up. Without this,
    // "call them Tuesday" is logged as a call that already took place.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'call', 'direction' => 'outbound',
        'occurred_at' => '2026-09-01 09:00:00',
        'summary' => 'Not yet happened',
    ])->assertSessionHasErrors('occurred_at');

    expect(CrmActivity::count())->toBe(0);
});

it('drops a direction and duration that make no sense for the kind', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'note', 'direction' => 'inbound', 'duration_seconds' => 90,
        'summary' => 'A note has no direction',
    ])->assertRedirect();

    // ⚠ Otherwise reports start counting "inbound notes" and 90-second notes.
    $activity = CrmActivity::sole();
    expect($activity->direction)->toBeNull()
        ->and($activity->duration_seconds)->toBeNull();
});

// ── C-CRMB-4 / C-CRMB-11: subject whitelist and tenant boundary ─────────────

it('attaches to a customer as readily as to a lead', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUST-1', 'name' => 'Adabor Grocery',
    ]);

    $this->actingAs($this->owner)->post("/crm/customer/{$customer->id}/activities", [
        'type' => 'visit', 'direction' => 'outbound', 'summary' => 'Dropped in with samples',
    ])->assertRedirect();

    expect(CrmActivity::sole()->subject_type)->toBe($customer->getMorphClass());
});

it('refuses a subject kind that is not a lead or a customer', function () {
    // ⚠⚠ Constrained at the ROUTE (`whereIn`), so an unknown kind never matches
    // and never reaches the controller as a null model.
    //
    // ⚠ The app declares a `Route::fallback` that redirects an unmatched URL to
    // the dashboard, so the observable result is a REDIRECT rather than a 404.
    // Asserted as it actually behaves rather than as I first assumed - and the
    // assertion that matters is that nothing was written.
    // ⚠ The app declares a GET-only `Route::fallback` that redirects an
    // unmatched URL to the dashboard, so the two verbs are refused differently:
    // the GET redirects, the POST is a 405 because no route accepts that method
    // at that URI. Asserted as it actually behaves rather than as I first
    // assumed - and the assertion that matters is that nothing was written.
    $this->actingAs($this->owner)->get('/crm/journal/1/timeline')->assertRedirect('/dashboard');
    $this->actingAs($this->owner)->post('/crm/journal/1/activities', ['type' => 'note', 'summary' => 'x'])
        ->assertMethodNotAllowed();

    expect(CrmActivity::count())->toBe(0);
});

it('cannot see or touch another business\'s lead', function () {
    TenantScope::enter($this->other->id);
    $foreign = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival lead', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    // ⚠⚠ The global scope hides it, so it 404s rather than rendering another
    // tenant's history. Tenant isolation is a first-class invariant.
    $this->actingAs($this->owner)->get("/crm/lead/{$foreign->id}/timeline")->assertNotFound();
    $this->actingAs($this->owner)->post("/crm/lead/{$foreign->id}/activities", ['type' => 'note', 'summary' => 'x'])->assertNotFound();

    TenantScope::enter($this->other->id);
    expect(CrmActivity::count())->toBe(0);
});

// ── Tasks: mutable, with a lifecycle ────────────────────────────────────────

it('adds a follow-up and completes it, recording who and when', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/tasks", [
        'title' => 'Call back about the 5kg order',
        'due_at' => '2026-08-12 10:00:00',
    ])->assertRedirect();

    $task = CrmTask::sole();
    expect($task->status)->toBe(TaskStatus::Open)
        ->and($task->assigned_to_user_id)->toBe($this->owner->id);

    Carbon::setTestNow('2026-08-12 11:00:00');
    $this->actingAs($this->owner)->put("/crm/tasks/{$task->id}", ['status' => 'done'])->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_by)->toBe($this->owner->id)
        ->and($task->completed_at->toDateTimeString())->toBe('2026-08-12 11:00:00');

    // ⚠⚠ C-CRMB-6. Completing a task must NOT invent an activity. What happened
    // on that call is a statement a person makes; generating one would put
    // words in their mouth that later read as evidence.
    expect(CrmActivity::count())->toBe(0);
});

it('cancels a follow-up rather than deleting it', function () {
    $task = CrmTask::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'title' => 'Call back', 'due_at' => '2026-08-12 10:00:00',
        'assigned_to_user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->put("/crm/tasks/{$task->id}", ['status' => 'cancelled'])->assertRedirect();

    // ⚠ "We decided not to call back" is information worth keeping.
    expect(CrmTask::count())->toBe(1)
        ->and(CrmTask::sole()->status)->toBe(TaskStatus::Cancelled);
});

it('clears the completion when a task is reopened', function () {
    $task = CrmTask::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'title' => 'Call back', 'due_at' => '2026-08-12 10:00:00',
        'assigned_to_user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->put("/crm/tasks/{$task->id}", ['status' => 'done']);
    $this->actingAs($this->owner)->put("/crm/tasks/{$task->id}", ['status' => 'open']);

    // ⚠ Otherwise the row claims two things at once: open, and completed by
    // somebody at a time.
    $task->refresh();
    expect($task->completed_at)->toBeNull()
        ->and($task->completed_by)->toBeNull();
});

it('counts a task overdue only while it is open', function () {
    $task = CrmTask::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'title' => 'Overdue thing', 'due_at' => '2026-08-01 10:00:00',
        'assigned_to_user_id' => $this->owner->id,
    ]);

    expect($task->isOverdue())->toBeTrue();

    // ⚠ A task completed late is DONE, not overdue. A queue that keeps shouting
    // about finished work is a queue people stop reading.
    $task->update(['status' => TaskStatus::Done, 'completed_at' => now()]);
    expect($task->fresh()->isOverdue())->toBeFalse();
});

// ── C-CRMB-8 / C-CRMB-10: assignment and the queue ──────────────────────────

it('lets Staff create a follow-up for themselves but not assign it to somebody else', function () {
    $staff = ($this->asRole)('Staff');
    // ⚠ Their own lead. Since 15 Sep 2026 somebody without view-all sees only
    // leads assigned to them or their team, and an unassigned lead 404s.
    $this->lead->update(['assigned_to_user_id' => $staff->id]);

    $this->actingAs($staff)->post("/crm/lead/{$this->lead->id}/tasks", [
        'title' => 'My own follow-up', 'due_at' => '2026-08-12 10:00:00',
    ])->assertRedirect();

    expect(CrmTask::sole()->assigned_to_user_id)->toBe($staff->id);

    // ⚠⚠ Deciding whose workload something lands in is a manager's job. Staff
    // hold crm.leads.manage but NOT crm.leads.assign.
    $this->actingAs($staff)->post("/crm/lead/{$this->lead->id}/tasks", [
        'title' => 'Somebody else\'s problem', 'due_at' => '2026-08-12 10:00:00',
        'assigned_to_user_id' => $this->owner->id,
    ])->assertSessionHasErrors('assigned_to_user_id');

    expect(CrmTask::count())->toBe(1);
});

it('shows Staff only their own queue, and a Manager everybody\'s', function () {
    $staff = ($this->asRole)('Staff');
    $manager = ($this->asRole)('Manager');

    foreach ([[$staff, 'Staff task'], [$manager, 'Manager task']] as [$user, $title]) {
        CrmTask::create([
            'business_id' => $this->business->id,
            'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
            'title' => $title, 'due_at' => '2026-08-12 10:00:00',
            'assigned_to_user_id' => $user->id,
        ]);
    }

    // ⚠⚠ C-CRMB-10. Without crm.tasks.view.all every agent would read every
    // other agent's follow-ups - noise, and a quiet disclosure.
    $this->actingAs($staff)->get('/crm/queue')->assertInertia(
        fn (AssertableInertia $p) => $p->where('seesEverybody', false)
            ->has('tasks.data', 1)
            ->where('tasks.data.0.title', 'Staff task')
            ->etc()
    );

    $this->actingAs($manager)->get('/crm/queue')->assertInertia(
        fn (AssertableInertia $p) => $p->where('seesEverybody', true)->has('tasks.data', 2)->etc()
    );
});

it('refuses a task assigned to somebody outside the business', function () {
    // ⚠⚠ C-CRMB-5. Membership is the business_user PIVOT - `users` has NO
    // business_id column, and a scoped exists rule against `users` would match
    // nothing and silently reject every valid agent.
    $outsider = User::factory()->create();

    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/tasks", [
        'title' => 'Assign to an outsider', 'due_at' => '2026-08-12 10:00:00',
        'assigned_to_user_id' => $outsider->id,
    ])->assertSessionHasErrors('assigned_to_user_id');

    expect(CrmTask::count())->toBe(0);
});

// ── Reachability and render ─────────────────────────────────────────────────

it('renders the timeline with history and follow-ups', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", ['type' => 'note', 'summary' => 'First contact']);
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/tasks", ['title' => 'Call back', 'due_at' => '2026-08-12 10:00:00']);

    $this->actingAs($this->owner)->get("/crm/lead/{$this->lead->id}/timeline")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('crm/timeline')
            ->where('subject.name', 'Rahim Traders')
            ->has('activities', 1)
            ->has('tasks', 1)
            ->where('canRecord', true)
            ->etc());
});

it('renders the queue', function () {
    $this->actingAs($this->owner)->get('/crm/queue')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('crm/queue')->etc());
});

it('keeps both screens away from somebody with no CRM permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->get('/crm/queue')->assertForbidden();
    $this->actingAs($outsider)->get("/crm/lead/{$this->lead->id}/timeline")->assertForbidden();
    $this->actingAs($outsider)->post("/crm/lead/{$this->lead->id}/activities", ['type' => 'note', 'summary' => 'x'])->assertForbidden();
});

// ── The structural guard ────────────────────────────────────────────────────

it('lets the screen set every field the activity model can hold', function () {
    // ⚠⚠ The products.barcode failure class, applied here. A fillable field the
    // screen cannot set is a field that is dead in production.
    // ⚠ Two posts since 15 Sep 2026: only a call has a duration and only a visit
    // has a location (CRM slice 2d), so no single activity can hold every field.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'call', 'direction' => 'inbound',
        'occurred_at' => '2026-08-09 09:00:00',
        'summary' => 'Everything set', 'body' => 'Detail here',
        'outcome' => 'connected', 'duration_seconds' => 240,
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/activities", [
        'type' => 'visit', 'direction' => 'outbound',
        'occurred_at' => '2026-08-09 10:00:00',
        'summary' => 'At the shop', 'body' => 'Detail here', 'outcome' => 'met',
        'latitude' => '23.8103000', 'longitude' => '90.4125000', 'gps_accuracy_meters' => '12.5',
    ])->assertSessionHasNoErrors();

    $activities = CrmActivity::all();

    $expected = collect((new CrmActivity)->getFillable())
        ->reject(fn ($f) => in_array($f, ['business_id', 'subject_type', 'subject_id'], true));

    $unsettable = $expected->reject(fn ($f) => $activities->contains(fn (CrmActivity $a) => filled($a->{$f})))->values()->all();

    expect($unsettable)->toBe([], 'these cannot be set from the screen: '.implode(', ', $unsettable));
});
