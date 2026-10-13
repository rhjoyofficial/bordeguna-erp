<?php

use App\Models\User;
use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 4 (spec §30-§31): accruals and prepayments - one amount
 * spread over months by an engine that posts real journals through
 * PostJournal, so period locks and the ledger's own rules always apply.
 *
 * ⚠ The rounding rule is the money rule: every month takes the even share
 * and the FINAL month takes the remainder, so the schedule releases exactly
 * its total. Releasing twice releases nothing extra - released_through is
 * the idempotency pointer.
 */
beforeEach(function () {
    // ⚠ Pinned: "asking beyond today" below assumed today was in September
    // 2026, so the test broke on 1 Oct 2026 when October became releasable.
    Carbon::setTestNow('2026-09-30 12:00:00');

    $this->business = Business::create(['name' => 'Amort Co', 'slug' => 'amort-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.journal.post', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Poster', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $this->poster = User::factory()->create();
    $this->poster->businesses()->attach($this->business->id);
    $this->poster->assignRole('Poster');

    $this->prepaid = Account::create(['code' => '1180', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'is_postable' => true]);
    $this->hosting = Account::firstOrCreate(['code' => '6225'], ['name' => 'Software and Hosting', 'type' => 'expense', 'is_postable' => true]);
});

afterEach(fn () => Carbon::setTestNow());

it('releases the months due, posting real journals with the remainder in the final month', function () {
    $schedule = AmortizationSchedule::create([
        'business_id' => $this->business->id,
        'kind' => 'prepaid_expense',
        'name' => 'Annual hosting paid in advance',
        'source_account_id' => $this->prepaid->id,
        'target_account_id' => $this->hosting->id,
        'total_amount' => 10000,
        'start_month' => '2026-07-01',
        'months' => 12,
        'created_by' => $this->poster->id,
    ]);

    // Through September: July, August, September = three releases.
    $released = app(ReleaseAmortizations::class)->through('2026-09-30', $this->poster);

    expect($released)->toBe(3)
        ->and($schedule->fresh()->released_through->toDateString())->toBe('2026-09-01');

    $journals = DB::table('journals')->where('memo', 'like', '%Annual hosting%')->count();
    expect($journals)->toBe(3);

    // Dr expense / Cr prepaid, 833.33 each so far.
    $expense = DB::table('journal_lines')->where('account_id', $this->hosting->id)->sum('amount_minor');
    $asset = DB::table('journal_lines')->where('account_id', $this->prepaid->id)->sum('amount_minor');
    expect($expense)->toBe(3 * 83333)
        ->and($asset)->toBe(-3 * 83333);

    // Releasing again through the same date does NOTHING more.
    expect(app(ReleaseAmortizations::class)->through('2026-09-30', $this->poster))->toBe(0);

    // Asking beyond today releases nothing extra - the ledger refuses
    // future journals and the engine respects it.
    expect(app(ReleaseAmortizations::class)->through('2027-06-30', $this->poster))->toBe(0);

    // When June 2027 actually arrives, the rest releases; the final month
    // carries the remainder and the schedule closes on exactly its total.
    $this->travelTo('2027-06-15');
    app(ReleaseAmortizations::class)->through('2027-06-30', $this->poster);

    $fresh = $schedule->fresh();
    expect($fresh->status)->toBe('done')
        ->and((int) DB::table('journal_lines')->where('account_id', $this->hosting->id)->sum('amount_minor'))
        ->toBe(1000000); // exactly 10,000.00 - never 9,999.96
});

it('handles deferred income the other way around', function () {
    $income = Account::create(['code' => '4100', 'name' => 'Service Income', 'type' => 'income', 'is_postable' => true]);
    $deferred = Account::create(['code' => '2150', 'name' => 'Deferred Income', 'type' => 'liability', 'is_postable' => true]);

    AmortizationSchedule::create([
        'business_id' => $this->business->id,
        'kind' => 'deferred_income',
        'name' => 'Yearly service contract billed up front',
        'source_account_id' => $deferred->id,
        'target_account_id' => $income->id,
        'total_amount' => 12000,
        'start_month' => '2026-09-01',
        'months' => 12,
        'created_by' => $this->poster->id,
    ]);

    app(ReleaseAmortizations::class)->through('2026-09-30', $this->poster);

    // Dr the liability down, Cr income: income lines are credits (negative).
    expect((int) DB::table('journal_lines')->where('account_id', $income->id)->sum('amount_minor'))->toBe(-100000)
        ->and((int) DB::table('journal_lines')->where('account_id', $deferred->id)->sum('amount_minor'))->toBe(100000);
});

it('creates a schedule from the screen and releases due months with the button', function () {
    $this->actingAs($this->poster)->withSession(['current_business_id' => $this->business->id])
        ->post(route('accounting.amortizations.store'), [
            'kind' => 'prepaid_expense',
            'name' => 'Insurance year',
            'source_account_id' => $this->prepaid->id,
            'target_account_id' => $this->hosting->id,
            'total_amount' => 6000,
            'start_month' => '2026-08-01',
            'months' => 6,
        ])
        ->assertSessionHasNoErrors();

    expect(AmortizationSchedule::count())->toBe(1);

    $this->actingAs($this->poster)->withSession(['current_business_id' => $this->business->id])
        ->post(route('accounting.amortizations.release'))
        ->assertSessionHasNoErrors();

    // August is due (and September if the clock has passed it) - at least one.
    expect(AmortizationSchedule::first()->released_through)->not->toBeNull();
});

it('keeps the screen read-only without the posting permission', function () {
    Role::firstOrCreate(['name' => 'AmortViewer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);
    $viewer = User::factory()->create();
    $viewer->businesses()->attach($this->business->id);
    $viewer->assignRole('AmortViewer');

    $this->actingAs($viewer)->withSession(['current_business_id' => $this->business->id])
        ->get(route('accounting.amortizations'))
        ->assertOk();

    $this->actingAs($viewer)->withSession(['current_business_id' => $this->business->id])
        ->post(route('accounting.amortizations.release'))
        ->assertForbidden();
});

// ─── Audit 13 Oct 2026 ───────────────────────────────────────────────────────

// ⚠⚠ A schedule is a manual journal on a timer: it may not touch a head a
// subsystem owns, a money ledger, or a head whose lines must name a party.
it('refuses a schedule on a money, subsystem-owned or party-kept head', function () {
    $cash = Account::create(['code' => '1110', 'name' => 'Cash in Hand', 'type' => 'asset', 'is_postable' => true]);
    $cash->forceFill(['system_slug' => 'cash_in_hand'])->save();

    $receivable = Account::create(['code' => '1140', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_postable' => true]);
    AccountMapping::create(['key' => 'sales.accounts_receivable', 'account_id' => $receivable->id, 'effective_from' => '2020-01-01']);

    $advances = Account::create(['code' => '1170', 'name' => 'Employee Advances', 'type' => 'asset', 'is_postable' => true]);
    $advances->forceFill(['system_slug' => 'employee_advances'])->save();

    foreach ([$cash, $receivable, $advances] as $refused) {
        $this->actingAs($this->poster)->withSession(['current_business_id' => $this->business->id])
            ->post(route('accounting.amortizations.store'), [
                'kind' => 'prepaid_expense',
                'name' => 'Not allowed',
                'source_account_id' => $refused->id,
                'target_account_id' => $this->hosting->id,
                'total_amount' => 6000,
                'start_month' => '2026-08-01',
                'months' => 6,
            ])
            ->assertSessionHasErrors('source_account_id');
    }

    expect(AmortizationSchedule::count())->toBe(0);
});

it('shows a release the ledger refuses as an error on the screen, not a 500', function () {
    $off = Account::create(['code' => '6998', 'name' => 'Old Expense', 'type' => 'expense', 'is_postable' => true, 'is_active' => false]);

    AmortizationSchedule::create([
        'business_id' => $this->business->id, 'kind' => 'prepaid_expense', 'name' => 'Broken',
        'source_account_id' => $this->prepaid->id, 'target_account_id' => $off->id,
        'total_amount' => 600, 'start_month' => '2026-09-01', 'months' => 6, 'created_by' => $this->poster->id,
    ]);

    $this->actingAs($this->poster)->withSession(['current_business_id' => $this->business->id])
        ->post(route('accounting.amortizations.release'))
        ->assertSessionHasErrors('release');
});

it('reports a failing schedule and still releases every other one', function () {
    $off = Account::create(['code' => '6998', 'name' => 'Old Expense', 'type' => 'expense', 'is_postable' => true, 'is_active' => false]);

    $broken = AmortizationSchedule::create([
        'business_id' => $this->business->id, 'kind' => 'prepaid_expense', 'name' => 'Broken',
        'source_account_id' => $this->prepaid->id, 'target_account_id' => $off->id,
        'total_amount' => 600, 'start_month' => '2026-09-01', 'months' => 6, 'created_by' => $this->poster->id,
    ]);
    $good = AmortizationSchedule::create([
        'business_id' => $this->business->id, 'kind' => 'prepaid_expense', 'name' => 'Good',
        'source_account_id' => $this->prepaid->id, 'target_account_id' => $this->hosting->id,
        'total_amount' => 600, 'start_month' => '2026-09-01', 'months' => 6, 'created_by' => $this->poster->id,
    ]);

    $this->artisan('accounting:release-amortizations')->assertFailed();

    expect($broken->fresh()->released_through)->toBeNull()
        ->and($good->fresh()->released_through->toDateString())->toBe('2026-09-01');
});

// ⚠⚠ The button and the nightly run reading the same pointer: whoever is
// second must find the month already released inside its transaction.
it('never posts a month another run released after this one read the schedule', function () {
    $schedule = AmortizationSchedule::create([
        'business_id' => $this->business->id, 'kind' => 'prepaid_expense', 'name' => 'Raced',
        'source_account_id' => $this->prepaid->id, 'target_account_id' => $this->hosting->id,
        'total_amount' => 600, 'start_month' => '2026-08-01', 'months' => 6, 'created_by' => $this->poster->id,
    ]);

    // The other run releases August the moment this one has read the list.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $schedule) {
        if (! $raced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'amortization_schedules') && str_contains($query->sql, 'status')) {
            $raced = true;
            DB::table('amortization_schedules')->where('id', $schedule->id)->update(['released_through' => '2026-08-01']);
        }
    });

    $posted = app(ReleaseAmortizations::class)->through('2026-09-30', $this->poster);

    expect($raced)->toBeTrue()
        ->and($posted)->toBe(1) // September only
        ->and(DB::table('journals')->where('memo', 'like', 'Raced - month 1 %')->count())->toBe(0)
        ->and($schedule->fresh()->released_through->toDateString())->toBe('2026-09-01');
});
