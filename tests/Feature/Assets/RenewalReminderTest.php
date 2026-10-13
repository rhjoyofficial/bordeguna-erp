<?php

use App\Models\User;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Notifications\AccountRenewalDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register slice 4: renewal reminders. 30 days, 7 days, overdue, each
 * once per renewal date, to the owner and to whoever manages the hub; never
 * for a closed account; the cycle restarts when the account is renewed.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    // ⚠ New pages are not in the committed build manifest; the screens are proved by props, not by Vite.
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->manager = ($this->user)('Manager');
    $this->custodian = ($this->user)('IT Custodian');
    $this->staff = ($this->user)('Staff');
    $this->stranger = User::factory()->create();
    $this->stranger->businesses()->attach($this->other->id);

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->rakib = Employee::create(['employee_code' => 'BDG-000002', 'first_name' => 'Rakib', 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01', 'user_id' => $this->staff->id]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->rakib->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);

    $this->account = fn (string $name, ?string $renewsOn, string $status = 'active', ?int $owner = null) => CompanyAccount::create([
        'kind' => 'domain', 'name' => $name, 'identifier' => $name, 'renews_on' => $renewsOn, 'status' => $status, 'owner_employee_id' => $owner, 'created_by' => $this->owner->id,
    ]);
    // ⚠ The fake's own assertCount flattens its nested store unevenly; count the entries ourselves.
    $this->sent = fn () => collect(Notification::sentNotifications())->flatten(2)->sum(fn ($list) => count($list));
    $this->run = function () {
        $this->artisan('assets:remind-renewals')->assertSuccessful();
        // The command leaves the tenant scope when it finishes.
        TenantScope::enter($this->business->id);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('reminds the owner and the hub managers at 30 days, 7 days and overdue, once each, and restarts after a renewal', function () {
    Notification::fake();

    $domain = ($this->account)('laladia.com', '2026-11-12', 'active', $this->rakib->id); // 30 days away
    $far = ($this->account)('far.com', '2026-11-13');                                    // 31 days: nothing today
    ($this->account)('closed.com', '2026-10-01', 'closed');                               // overdue but closed: nothing
    ($this->account)('nodate.com', null);

    $this->artisan('assets:remind-renewals --dry-run')->expectsOutputToContain('would tell')->assertSuccessful();
    Notification::assertNothingSent();
    TenantScope::enter($this->business->id);

    ($this->run)();

    // Owner (hub manager), IT Custodian (hub manager) and Rakib (the account's owner); not the Manager, who only reads the hub.
    Notification::assertSentTo([$this->owner, $this->custodian, $this->staff], AccountRenewalDueNotification::class, function (AccountRenewalDueNotification $n) use ($domain) {
        expect($n->stage)->toBe('30')
            ->and($n->accountId)->toBe($domain->id)
            ->and($n->days)->toBe(30)
            ->and($n->summary())->toBe('laladia.com (Domain) renews on 2026-11-12, in 30 days.')
            ->and($n->toDatabase($this->owner)['url'])->toBe("/assets/accounts/{$domain->id}");

        return true;
    });
    Notification::assertNotSentTo([$this->manager, $this->stranger], AccountRenewalDueNotification::class);
    expect(($this->sent)())->toBe(3);
    expect($domain->fresh()->renewal_reminded_stage)->toBe('30')->and($domain->fresh()->renewal_reminded_for->toDateString())->toBe('2026-11-12');

    // The next day, and the day after: silence. Stage 30 was said. (far.com is moved out of the way first.)
    CompanyAccount::query()->whereKey($far->id)->update(['renews_on' => '2027-06-01']);
    Carbon::setTestNow('2026-10-14 10:00:00');
    ($this->run)();
    Carbon::setTestNow('2026-10-20 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(3);

    // Seven days before: stage 7, once.
    Carbon::setTestNow('2026-11-05 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(6);
    Carbon::setTestNow('2026-11-06 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(6);
    expect($domain->fresh()->renewal_reminded_stage)->toBe('7');

    // The day after the date: overdue, once; then quiet.
    Carbon::setTestNow('2026-11-13 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(9);
    Notification::assertSentTo($this->owner, AccountRenewalDueNotification::class, fn (AccountRenewalDueNotification $n) => $n->stage === 'overdue' && $n->summary() === 'laladia.com (Domain) renewal is overdue: it was due 2026-11-12, 1 day ago.');
    Carbon::setTestNow('2026-11-20 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(9);

    // Renewed to a date 20 days out: the cycle restarts and stage 30 fires again for the NEW date.
    $this->actingAs($this->owner)->post("/assets/accounts/{$domain->id}/renewed", ['next_renews_on' => '2026-12-10'])->assertSessionHasNoErrors();
    ($this->run)();
    expect(($this->sent)())->toBe(12);
    expect($domain->fresh()->renewal_reminded_for->toDateString())->toBe('2026-12-10')->and($domain->fresh()->renewal_reminded_stage)->toBe('30');

    // Closing it stops everything.
    CompanyAccount::query()->whereKey($domain->id)->update(['status' => 'closed', 'renewal_reminded_stage' => null, 'renewal_reminded_for' => null]);
    Carbon::setTestNow('2026-12-20 10:00:00');
    ($this->run)();
    expect(($this->sent)())->toBe(12);

    // Another business's run says nothing about this one.
    TenantScope::enter($this->other->id);
    $this->artisan('assets:remind-renewals', ['--business' => $this->other->id])->expectsOutputToContain('Other Co: 0 account(s) reminded')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'assets:remind-renewals'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 8 * * *');
});

it('names the stage the date calls for', function () {
    $account = ($this->account)('x.com', '2026-10-20');
    $today = Carbon::parse('2026-10-13');

    expect($account->renewalStage($today))->toBe('7')
        ->and((clone $account)->forceFill(['renews_on' => '2026-10-13'])->renewalStage($today))->toBe('7')
        ->and((clone $account)->forceFill(['renews_on' => '2026-10-21'])->renewalStage($today))->toBe('30')
        ->and((clone $account)->forceFill(['renews_on' => '2026-11-12'])->renewalStage($today))->toBe('30')
        ->and((clone $account)->forceFill(['renews_on' => '2026-11-13'])->renewalStage($today))->toBeNull()
        ->and((clone $account)->forceFill(['renews_on' => '2026-10-12'])->renewalStage($today))->toBe('overdue')
        ->and((clone $account)->forceFill(['status' => 'closed'])->renewalStage($today))->toBeNull();
});
