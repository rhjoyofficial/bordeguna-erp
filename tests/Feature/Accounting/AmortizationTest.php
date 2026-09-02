<?php

use App\Models\User;
use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
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
    $this->hosting = Account::create(['code' => '6225', 'name' => 'Software and Hosting', 'type' => 'expense', 'is_postable' => true]);
});

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
