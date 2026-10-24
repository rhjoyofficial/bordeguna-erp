<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\CrmBadges;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM round 3 (16 Oct 2026): the two sidebar counts and the lead list's quick
 * views.
 *
 * ⚠ Badges follow the Failed jobs pattern: null, not 0, for somebody without
 * crm.leads.view, and the follow-up count is the reader's OWN scope, the
 * queue's rule. The quick views count inside the reader's visibility, with
 * the rest of the filter bar applied.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 10:00:00');
    Cache::flush();
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Badge Co', 'slug' => 'badge-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $name, ?string $role): User {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    };

    $this->owner = ($this->user)('Owner', 'Owner');
    $this->tanvir = ($this->user)('Tanvir', 'Sales Officer');
    $this->abir = ($this->user)('Abir', 'Sales Officer');
    $this->nobody = ($this->user)('Nobody', null);

    $this->lead = fn (string $name, ?User $holder, array $extra = []) => Lead::create(array_merge([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => $name,
        'district' => 'Dhaka', 'thana' => 'Mirpur', 'status' => LeadStatus::New, 'assigned_to_user_id' => $holder?->id,
    ], $extra));

    $this->task = fn (Lead $lead, ?User $holder, string $due, string $status = 'open') => CrmTask::create([
        'business_id' => $this->business->id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'title' => 'Call '.$lead->name, 'due_at' => $due, 'assigned_to_user_id' => $holder?->id, 'status' => $status,
    ]);

    $this->as = function (User $user) {
        app()->forgetScopedInstances();
        auth()->forgetGuards();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->props = fn (User $user, string $url = '/crm/leads') => ($this->as)($user)->get($url)->assertOk()->viewData('page')['props'];
});

afterEach(fn () => Carbon::setTestNow());

/* -------------------------------------------------------------------------
 | Badges
 * ------------------------------------------------------------------------- */

it('withholds both CRM counts from somebody who may not view leads', function () {
    expect(CrmBadges::followUpsDue($this->nobody, $this->business->id))->toBeNull()
        ->and(CrmBadges::ticketsOverdue($this->nobody, $this->business->id))->toBeNull()
        ->and(CrmBadges::followUpsDue(null, $this->business->id))->toBeNull();
});

it('counts follow-ups due today and overdue in the reader\'s own scope', function () {
    $mine = ($this->lead)('Mine', $this->tanvir);
    $his = ($this->lead)('His', $this->abir);

    ($this->task)($mine, $this->tanvir, '2026-10-16 18:00:00');   // later today: due
    ($this->task)($mine, $this->tanvir, '2026-10-12 09:00:00');   // overdue
    ($this->task)($mine, $this->tanvir, '2026-10-17 09:00:00');   // tomorrow: not yet
    ($this->task)($mine, $this->tanvir, '2026-10-12 09:00:00', 'done');
    ($this->task)($his, $this->abir, '2026-10-16 09:00:00');

    $this->actingAs($this->tanvir);
    app()->forgetScopedInstances();
    expect(CrmBadges::followUpsDue($this->tanvir, $this->business->id))->toBe(2);

    app()->forgetScopedInstances();
    $this->actingAs($this->owner);
    expect(CrmBadges::followUpsDue($this->owner, $this->business->id))->toBe(3);
});

it('counts tickets still open past their resolve-by, and shares both counts on every page', function () {
    // Tickets are hidden since 24 Oct 2026 (Joy); the badge is tested switched on.
    config(['features.crm_tickets' => true]);

    $ticket = fn (string $number, string $status, string $resolveBy) => DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id, 'ticket_number' => $number, 'caller_name' => 'A', 'subject' => 'Late',
        'category' => 'delivery', 'priority' => 'high', 'status' => $status, 'resolve_by' => $resolveBy,
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ticket('T-1', 'open', '2026-10-15 09:00:00');
    $ticket('T-2', 'in_progress', '2026-10-16 09:00:00');
    $ticket('T-3', 'open', '2026-10-18 09:00:00');      // still in time
    $ticket('T-4', 'resolved', '2026-10-10 09:00:00');  // late but closed

    $props = ($this->props)($this->owner);

    expect($props['crmTicketsOverdueCount'])->toBe(2)
        ->and($props['crmFollowUpsDueCount'])->toBe(0);

    expect(($this->as)($this->nobody)->get('/dashboard')->viewData('page')['props']['crmTicketsOverdueCount'] ?? null)->toBeNull();

    // Switched off, nobody gets the count: no badge points at a 404.
    config(['features.crm_tickets' => false]);
    expect(($this->props)($this->owner)['crmTicketsOverdueCount'])->toBeNull();
});

it('holds a badge for a minute rather than counting on every page', function () {
    $lead = ($this->lead)('Mine', $this->tanvir);
    ($this->task)($lead, $this->tanvir, '2026-10-16 09:00:00');

    $this->actingAs($this->tanvir);
    expect(CrmBadges::followUpsDue($this->tanvir, $this->business->id))->toBe(1);

    ($this->task)($lead, $this->tanvir, '2026-10-16 09:30:00');
    expect(CrmBadges::followUpsDue($this->tanvir, $this->business->id))->toBe(1);

    Carbon::setTestNow('2026-10-16 10:02:00');
    expect(CrmBadges::followUpsDue($this->tanvir, $this->business->id))->toBe(2);
});

/* -------------------------------------------------------------------------
 | Quick views on the lead list
 * ------------------------------------------------------------------------- */

it('counts every quick view inside the reader\'s visibility', function () {
    $today = ($this->lead)('Today', $this->tanvir);
    ($this->task)($today, $this->tanvir, '2026-10-16 15:00:00');
    ($this->lead)('Verified', $this->tanvir)->forceFill(['verified_at' => now()])->saveQuietly();
    $cold = ($this->lead)('Cold', $this->tanvir);
    $cold->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
    ($this->lead)('Archived', $this->tanvir)->forceFill(['archived_at' => now()])->saveQuietly();
    ($this->lead)('Dup', $this->tanvir)->forceFill(['duplicate_of_lead_id' => $today->id])->saveQuietly();
    ($this->lead)('Not mine', $this->abir);

    $views = ($this->props)($this->tanvir)['views'];

    expect($views)->toBe([
        'working' => 3,
        'today' => 1,
        'unverified' => 2,
        'cold' => 1,
        'duplicates' => 1,
        'archived' => 1,
        'all' => 5,
    ]);
});

it('narrows the counts by the rest of the filter bar', function () {
    ($this->lead)('Dhaka shop', $this->owner);
    ($this->lead)('Sylhet shop', $this->owner, ['district' => 'Sylhet', 'thana' => 'Kotwali']);

    expect(($this->props)($this->owner, '/crm/leads?district=Sylhet')['views']['working'])->toBe(1);
});

it('lists the leads with a follow-up due today or overdue', function () {
    ($this->task)(($this->lead)('Due today', $this->owner), $this->owner, '2026-10-16 17:00:00');
    ($this->task)(($this->lead)('Overdue', $this->owner), $this->owner, '2026-10-10 17:00:00');
    ($this->task)(($this->lead)('Next week', $this->owner), $this->owner, '2026-10-22 17:00:00');
    ($this->task)(($this->lead)('Done', $this->owner), $this->owner, '2026-10-16 08:00:00', 'done');

    $names = collect(($this->props)($this->owner, '/crm/leads?follow_up=today')['leads']['data'])->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Due today', 'Overdue']);
});

it('lists the leads going cold', function () {
    ($this->lead)('Fresh', $this->owner);
    $cold = ($this->lead)('Quiet shop', $this->owner);
    $cold->forceFill(['created_at' => now()->subDays(16)])->saveQuietly();
    $distributor = ($this->lead)('Quiet distributor', $this->owner, ['lead_type' => 'distributor']);
    $distributor->forceFill(['created_at' => now()->subDays(16)])->saveQuietly();

    $rows = collect(($this->props)($this->owner, '/crm/leads?cold=1')['leads']['data']);

    expect($rows->pluck('name')->all())->toBe(['Quiet shop'])
        ->and($rows->first()['is_cold'])->toBeTrue()
        ->and($rows->first()['quiet_days'])->toBe(16);
});

it('shows the next follow-up and the last contact on each row', function () {
    $lead = ($this->lead)('Maa Store', $this->owner);
    ($this->task)($lead, $this->owner, '2026-10-20 11:00:00');
    ($this->task)($lead, $this->owner, '2026-10-14 11:00:00');
    CrmActivity::create([
        'business_id' => $this->business->id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'type' => 'visit', 'summary' => 'Visited', 'occurred_at' => '2026-10-09 16:40:00', 'recorded_by' => $this->owner->id,
    ]);
    ($this->lead)('Never called', $this->owner);

    $rows = collect(($this->props)($this->owner)['leads']['data'])->keyBy('name');

    expect($rows['Maa Store']['next_follow_up'])->toMatchArray(['at' => '2026-10-14 11:00:00', 'overdue' => true, 'today' => false])
        ->and($rows['Maa Store']['last_contact'])->toMatchArray(['at' => '2026-10-09 16:40:00', 'type' => 'visit', 'label' => 'Visit'])
        ->and($rows['Never called']['next_follow_up'])->toBeNull()
        ->and($rows['Never called']['last_contact'])->toBeNull();
});

it('tells the screen whether the reader may write to a lead', function () {
    expect(($this->props)($this->tanvir)['can_manage'])->toBeTrue();

    $viewer = ($this->user)('Viewer', null);
    setPermissionsTeamId($this->business->id);
    $viewer->givePermissionTo('crm.leads.view');

    expect(($this->props)($viewer)['can_manage'])->toBeFalse();
});
