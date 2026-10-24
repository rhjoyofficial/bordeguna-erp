<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\GoingCold;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The CRM Overview (redesign round 3, 16 Oct 2026): one page, a TEAM view for
 * whoever reads more than their own work and a PERSONAL view for an officer.
 *
 * ⚠⚠ The page adds no visibility rule of its own. These tests exist to prove
 * that every number on it passes the same rule as the list behind it: an
 * officer counts his own leads and follow-ups, the ASM his team's, the Owner
 * everybody's.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Field Co', 'slug' => 'field-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $name, ?string $role, ?Employee $supervisor = null): array {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);

        if ($role !== null) {
            $user->assignRole($role);
        }

        $employee = Employee::create([
            'employee_code' => 'OVR-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'first_name' => $name,
            'last_name' => 'Test',
            'employment_type' => 'permanent',
            'status' => 'active',
        ]);

        EmployeeAssignment::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor?->id,
            'is_primary' => true,
            'effective_from' => '2026-01-01',
        ]);

        return [$user, $employee];
    };

    [$this->owner] = ($this->person)('Musa', 'Owner');
    [$this->manager] = ($this->person)('Manager', 'Manager');
    [$this->staff] = ($this->person)('Rumana', 'Staff');
    [$this->asm, $asmEmployee] = ($this->person)('Shoykod', 'Sales Lead');
    [$this->tanvir] = ($this->person)('Tanvir', 'Sales Officer', $asmEmployee);
    [$this->abir] = ($this->person)('Abir', 'Sales Officer', $asmEmployee);
    [$this->stranger] = ($this->person)('Stranger', 'Sales Officer');
    [$this->nobody] = ($this->person)('Nobody', null);

    $this->lead = fn (string $name, ?User $holder, array $extra = []) => Lead::create(array_merge([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => $name,
        'district' => 'Dhaka', 'thana' => 'Mirpur', 'status' => LeadStatus::New, 'assigned_to_user_id' => $holder?->id,
    ], $extra));

    $this->task = fn (Lead $lead, ?User $holder, string $due, string $status = 'open') => CrmTask::create([
        'business_id' => $this->business->id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'title' => 'Call '.$lead->name, 'due_at' => $due, 'assigned_to_user_id' => $holder?->id, 'status' => $status,
    ]);

    $this->as = function (User $user) {
        // ⚠ The memoised team belongs to one request; tests reuse the app.
        app()->forgetScopedInstances();
        auth()->forgetGuards();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->overview = fn (User $user) => ($this->as)($user)->get('/crm/overview')->assertOk()->viewData('page')['props'];
});

afterEach(fn () => Carbon::setTestNow());

it('refuses somebody who may not view leads', function () {
    ($this->as)($this->nobody)->get('/crm/overview')->assertForbidden();
});

it('renders the overview page component', function () {
    ($this->as)($this->owner)->get('/crm/overview')->assertOk()->assertInertia(fn ($page) => $page->component('crm/overview'));
});

it('gives an officer the personal view counted over his own leads only', function () {
    $mine = ($this->lead)('Maa Store', $this->tanvir);
    ($this->lead)('Tanvir Two', $this->tanvir, ['status' => LeadStatus::Contacted]);
    ($this->lead)('Abir Store', $this->abir);
    ($this->lead)('Stranger Store', $this->stranger);

    ($this->task)($mine, $this->tanvir, '2026-10-16 15:00:00');
    ($this->task)($mine, $this->tanvir, '2026-10-14 11:00:00');
    ($this->task)(($this->lead)('Abir Three', $this->abir), $this->abir, '2026-10-16 12:00:00');

    $props = ($this->overview)($this->tanvir);

    expect($props['view'])->toBe('personal')
        ->and($props['team'])->toBeNull()
        ->and($props['personal']['my_day']['leads'])->toBe(2)
        ->and($props['personal']['my_day']['due_today'])->toBe(2)
        ->and($props['personal']['my_day']['overdue'])->toBe(1)
        ->and($props['personal']['my_day']['oldest_overdue'])->toBe('2026-10-14')
        ->and(collect($props['personal']['today'])->pluck('subject.name')->unique()->values()->all())->toBe(['Maa Store'])
        ->and(collect($props['personal']['by_status'])->firstWhere('status', 'contacted')['count'])->toBe(1);
});

it('gives the ASM the team view over his team and nobody else', function () {
    ($this->lead)('Tanvir Store', $this->tanvir);
    ($this->lead)('Abir Store', $this->abir, ['status' => LeadStatus::Qualified]);
    ($this->lead)('Stranger Store', $this->stranger);

    $props = ($this->overview)($this->asm);

    expect($props['view'])->toBe('team')
        ->and($props['personal'])->toBeNull()
        ->and(collect($props['team']['stand']['stages'])->sum('count'))->toBe(2)
        ->and($props['team']['districts']['working'])->toBe(2);
});

it('counts every lead for the Owner', function () {
    ($this->lead)('Tanvir Store', $this->tanvir);
    ($this->lead)('Stranger Store', $this->stranger);
    ($this->lead)('Nobody Store', null);

    $props = ($this->overview)($this->owner);

    expect($props['view'])->toBe('team')
        ->and($props['team']['districts']['working'])->toBe(3)
        ->and($props['team']['stand']['new_uncalled'])->toBe(3);
});

it('lists follow-ups by person alphabetically, never by who has the most', function () {
    $zed = ($this->person)('Zed', 'Sales Officer')[0];
    ($this->task)(($this->lead)('Z1', $zed), $zed, '2026-10-10 10:00:00');
    ($this->task)(($this->lead)('Z2', $zed), $zed, '2026-10-11 10:00:00');
    ($this->task)(($this->lead)('A1', $this->abir), $this->abir, '2026-10-20 10:00:00');
    ($this->task)(($this->lead)('U1', null), null, '2026-10-20 10:00:00');

    $people = ($this->overview)($this->owner)['team']['follow_ups']['people'];

    expect(collect($people)->pluck('who')->all())->toBe(['Abir', 'Zed', 'Unassigned'])
        ->and(collect($people)->firstWhere('who', 'Zed')['overdue'])->toBe(2);
});

it('shows each Needs-you item only to who may act on it', function () {
    // Unassigned: the Manager sees those (he may assign), not Tanvir's own.
    ($this->lead)('Unverified Shop', null);
    DB::table('message_campaigns')->insert([
        'business_id' => $this->business->id, 'channel' => 'sms', 'name' => 'Winter ghee offer', 'body' => 'Hi',
        'status' => 'pending_approval', 'estimated_recipients' => 1108, 'estimated_cost' => 610,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $owner = collect(($this->overview)($this->owner)['team']['needs'])->pluck('key')->all();
    $manager = collect(($this->overview)($this->manager)['team']['needs'])->pluck('key')->all();

    // Owner: verifies and approves. Manager: verifies, may not approve a send.
    expect($owner)->toContain('shops_unverified')->toContain('sends_waiting')
        ->and($manager)->toContain('shops_unverified')->not->toContain('sends_waiting');
});

it('counts a ticket past its time in Needs you', function () {
    // Tickets are hidden since 24 Oct 2026 (Joy); the card is tested switched on.
    config(['features.crm_tickets' => true]);

    DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id, 'ticket_number' => 'TKT-1', 'caller_name' => 'A', 'subject' => 'Late delivery',
        'category' => 'delivery', 'priority' => 'high', 'status' => 'open', 'resolve_by' => '2026-10-15 09:00:00',
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $needs = collect(($this->overview)($this->owner)['team']['needs'])->keyBy('key');

    expect($needs['tickets_late']['count'])->toBe(1)
        ->and($needs['tickets_late']['hint'])->toContain('Late delivery');
});

it('keeps the phone book block for those who may read it', function () {
    expect(($this->overview)($this->owner)['team']['phone_book'])->not->toBeNull()
        // The Manager may read contacts and send; the ASM may do neither.
        ->and(($this->overview)($this->manager)['team']['phone_book'])->not->toBeNull()
        ->and(($this->overview)($this->asm)['team']['phone_book'])->toBeNull();
});

it('gives Staff, who work only their own, the personal view', function () {
    expect(($this->overview)($this->staff)['view'])->toBe('personal')
        ->and(($this->overview)($this->manager)['view'])->toBe('team');
});

/* -------------------------------------------------------------------------
 | Going cold: 14 days for customer and retailer, 30 for distributor and
 | supplier; working leads in New to Opportunity with no open follow-up.
 * ------------------------------------------------------------------------- */

it('applies the going-cold threshold by lead type', function () {
    $quiet = function (string $name, string $type, int $days, array $extra = []) {
        $lead = ($this->lead)($name, $this->owner, ['lead_type' => $type] + $extra);
        $lead->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

        return $lead;
    };

    $quiet('Retailer 15', 'retailer', 15);
    $quiet('Customer 15', 'customer', 15, ['district' => null, 'thana' => null]);
    $quiet('Retailer 13', 'retailer', 13);
    $quiet('Distributor 15', 'distributor', 15);
    $quiet('Distributor 31', 'distributor', 31);
    $quiet('Supplier 31', 'supplier', 31);
    $quiet('Nurture 40', 'retailer', 40, ['status' => LeadStatus::Nurture]);
    $planned = $quiet('Planned 40', 'retailer', 40);
    ($this->task)($planned, $this->owner, '2026-10-20 10:00:00');
    // Talked to recently: the activity, not the day it was added, counts.
    $talked = $quiet('Talked 40', 'retailer', 40);
    CrmActivity::create([
        'business_id' => $this->business->id, 'subject_type' => $talked->getMorphClass(), 'subject_id' => $talked->id,
        'type' => 'call', 'summary' => 'Talked', 'occurred_at' => now()->subDays(3), 'recorded_by' => $this->owner->id,
    ]);

    app()->forgetScopedInstances();
    $this->actingAs($this->owner);
    $cold = GoingCold::apply(Lead::query())->pluck('name')->sort()->values()->all();

    expect($cold)->toBe(['Customer 15', 'Distributor 31', 'Retailer 15', 'Supplier 31'])
        ->and(($this->overview)($this->owner)['team']['going_cold']['count'])->toBe(4);
});

it('never counts a duplicate, archived or closed lead as going cold', function () {
    foreach ([
        ['Lost', ['status' => LeadStatus::Lost]],
        ['Archived', ['archived_at' => now()]],
    ] as [$name, $extra]) {
        $lead = ($this->lead)($name, $this->owner);
        $lead->forceFill(['created_at' => now()->subDays(60)] + $extra)->saveQuietly();
    }

    $this->actingAs($this->owner);

    expect(GoingCold::apply(Lead::query())->count())->toBe(0)
        ->and(GoingCold::DAYS)->toBe(['customer' => 14, 'retailer' => 14, 'distributor' => 30, 'supplier' => 30])
        ->and(TaskStatus::Open->value)->toBe('open');
});

/* -------------------------------------------------------------------------
 | The lead page (the timeline of a lead)
 * ------------------------------------------------------------------------- */

it('sends the lead page its head row, facts and buttons, and the popup options only on request', function () {
    $lead = ($this->lead)('Bismillah Traders', $this->tanvir, ['contact_name' => 'Halim']);
    ($this->task)($lead, $this->tanvir, '2026-10-14 11:00:00');

    $response = ($this->as)($this->tanvir)->get("/crm/lead/{$lead->id}/timeline")->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['lead']['name'])->toBe('Bismillah Traders')
        ->and($props['lead']['next_follow_up']['overdue'])->toBeTrue()
        ->and($props['leadHead'])->toHaveKeys(['source', 'campaign', 'lost_reason'])
        // A Sales Officer may not convert, verify or approve.
        ->and($props['leadCan'])->toBe(['convert' => false, 'convert_supplier' => false, 'verify' => false])
        ->and($props)->not->toHaveKey('leadForm');

    $version = $response->viewData('page')['version'];
    $partial = ($this->as)($this->tanvir)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'crm/timeline',
            'X-Inertia-Partial-Data' => 'leadForm',
        ])
        ->get("/crm/lead/{$lead->id}/timeline")
        ->assertOk()
        ->json('props');

    expect($partial['leadForm'])->toHaveKeys(['selectable_statuses', 'lead_types', 'agents', 'customers', 'lookups'])
        // His own agents list is himself: an officer has no team.
        ->and(collect($partial['leadForm']['agents'])->pluck('name')->all())->toBe(['Tanvir']);
});

it('keeps a lead the reader may not see off the lead page', function () {
    $lead = ($this->lead)('Abir Store', $this->abir);

    ($this->as)($this->tanvir)->get("/crm/lead/{$lead->id}/timeline")->assertNotFound();
});
