<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\ConfirmDisbursementBatch;
use App\Modules\Hrm\Actions\PostPayrollAccrual;
use App\Modules\Hrm\Actions\RecordPayslipPayment;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Models\PayslipPayment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The live incident of 12 Oct 2026, replayed.
 *
 * September 2026's payroll was approved on 28 Sep while accounting was still
 * switched off, so it posted no accrual. On 12 Oct the owner confirmed both
 * disbursement batches; the bank had paid on 30 Sep, but the batch screen sent
 * "today" without asking. Nine "Salary paid" journals landed on 12 Oct against
 * a payable nobody had accrued.
 *
 * Three fixes, three groups of tests: the batch now asks for the paid date; a
 * payment posts the missing accrual first (and the payroll screen can post it
 * on its own); and the owner can move a wrongly dated payment to the right day
 * by reversal and re-posting, never by editing a journal.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-12 11:00:00');

    $this->business = Business::create(['name' => 'Salary Co', 'slug' => 'salary-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'HR Manager'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web'])
            ->givePermissionTo('hrm.payroll.manage');
    }

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->hrManager = User::factory()->create();
    $this->hrManager->businesses()->attach($this->business->id);
    $this->hrManager->assignRole('HR Manager');

    $this->period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 9,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'divisor_days' => 30,
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // ⚠ Created straight into Disbursed: that is the incident's state, a run
    // that went through approval while there was no ledger to post to.
    $this->run = PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $this->period->id,
        'version' => 1,
        'status' => PayrollStatus::Disbursed,
        'employee_count' => 2,
        'total_net' => '50000.00',
    ]);

    foreach ([['BDG-000001', 'Karim', '30000.00'], ['BDG-000002', 'Rahim', '20000.00']] as [$code, $first, $net]) {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Test',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);

        $payslip = Payslip::create([
            'business_id' => $this->business->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id,
            'gross_salary' => $net, 'total_earnings' => $net, 'total_deductions' => '0.00', 'net' => $net,
        ]);

        PayslipLine::create([
            'payslip_id' => $payslip->id, 'code' => 'basic', 'label' => 'Basic', 'type' => 'earning',
            'amount' => $net, 'formula' => 'fixed', 'source' => 'test', 'sequence' => 1,
        ]);
    }

    $this->batch = DisbursementBatch::create([
        'business_id' => $this->business->id,
        'payroll_run_id' => $this->run->id,
        'reference' => 'DIS-202609-V1-BANKTRANSFER',
        'method' => 'bank_transfer',
        'created_by' => $this->owner->id,
    ]);
    $this->batch->payslips()->attach($this->run->payslips()->pluck('id'));
});

function pdAs(User $user)
{
    return test()->actingAs($user)->withSession(['current_business_id' => test()->business->id]);
}

/** Sum of an account's lines, in paisa, optionally on one day only. */
function pdSum(string $slug, ?string $on = null): int
{
    return (int) JournalLine::query()
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('journal_lines.account_id', Account::bySlug($slug)->id)
        ->when($on !== null, fn ($q) => $q->whereDate('journals.journal_date', $on))
        ->sum('journal_lines.amount_minor');
}

function pdPaymentJournals()
{
    return Journal::where('source_type', (new PayslipPayment)->getMorphClass());
}

function pdAccruals()
{
    return Journal::where('source_type', test()->run->getMorphClass())->where('source_id', test()->run->id);
}

// --- 1. The batch asks for the paid date ------------------------------------

it('refuses to confirm a batch without the paid date', function () {
    pdAs($this->owner)->post(route('hrm.batches.confirm', $this->batch), [
        'money_account_id' => $this->bank->id,
    ])->assertSessionHasErrors('paid_on');

    expect($this->batch->fresh()->confirmed_at)->toBeNull()
        ->and(pdPaymentJournals()->count())->toBe(0);
});

it('refuses a paid date in the future', function () {
    pdAs($this->owner)->post(route('hrm.batches.confirm', $this->batch), [
        'paid_on' => '2026-10-13',
        'money_account_id' => $this->bank->id,
    ])->assertSessionHasErrors('paid_on');

    expect($this->batch->fresh()->confirmed_at)->toBeNull();
});

it('dates every payment and every journal on the bank date it was given, not today', function () {
    pdAs($this->owner)->post(route('hrm.batches.confirm', $this->batch), [
        'paid_on' => '2026-09-30',
        'money_account_id' => $this->bank->id,
    ])->assertSessionHasNoErrors();

    expect(PayslipPayment::pluck('paid_on')->map->toDateString()->unique()->all())->toBe(['2026-09-30'])
        ->and(pdPaymentJournals()->count())->toBe(2)
        ->and(pdPaymentJournals()->get()->map(fn ($j) => $j->journal_date->toDateString())->unique()->all())->toBe(['2026-09-30'])
        ->and(pdSum('cash_at_bank', '2026-10-12'))->toBe(0)
        ->and(pdSum('cash_at_bank', '2026-09-30'))->toBe(-5000000);
});

it('makes the screen send the date the person typed, not today', function () {
    $source = file_get_contents(resource_path('js/pages/hrm/payroll/disbursement.tsx'));
    $confirm = substr($source, strpos($source, "route('hrm.batches.confirm'"), 400);

    expect($confirm)->toContain('paid_on: paidOn')
        ->and($confirm)->not->toContain('paid_on: today()');
});

// --- 2. The missing accrual -------------------------------------------------

it('posts the missing accrual before the first payment, dated at the period end', function () {
    expect(app(PostPayrollAccrual::class)->isMissingFor($this->run))->toBeTrue();

    app(ConfirmDisbursementBatch::class)->handle($this->batch, $this->owner, Carbon::parse('2026-09-30'), null, $this->bank);

    // ⚠ Once, although two payslips were paid through it.
    expect(pdAccruals()->count())->toBe(1)
        ->and(pdAccruals()->first()->journal_date->toDateString())->toBe('2026-09-30')
        // Accrued and paid: nothing owed, and the cost is in September.
        ->and(pdSum('salaries_payable'))->toBe(0)
        ->and(pdSum('salaries_expense'))->toBe(5000000)
        ->and(app(PostPayrollAccrual::class)->isMissingFor($this->run->fresh()))->toBeFalse();
});

it('does not post the accrual twice when a later single payment follows', function () {
    $first = $this->run->payslips()->orderBy('id')->first();
    $second = $this->run->payslips()->orderByDesc('id')->first();

    app(RecordPayslipPayment::class)->paid($first, $this->owner, (float) $first->net, null, Carbon::parse('2026-09-30'), null, null, $this->bank);
    app(RecordPayslipPayment::class)->paid($second, $this->owner, (float) $second->net, null, Carbon::parse('2026-09-30'), null, null, $this->bank);

    expect(pdAccruals()->count())->toBe(1)
        ->and(pdSum('salaries_payable'))->toBe(0);
});

it('refuses the payment, with the reason, when the accrual cannot be posted', function () {
    // The period end (30 Sep) is closed, so the accrual cannot land, and paying
    // against a payable nobody accrued is exactly the incident.
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->owner, 'September close');

    expect(fn () => app(ConfirmDisbursementBatch::class)->handle($this->batch, $this->owner, Carbon::parse('2026-10-01'), null, $this->bank))
        ->toThrow(ValidationException::class);

    expect($this->batch->fresh()->confirmed_at)->toBeNull()
        ->and(PayslipPayment::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('lets the payroll screen post the missing entry, once', function () {
    pdAs($this->hrManager)->get(route('hrm.payroll.index', ['year' => 2026, 'month' => 9]))
        ->assertInertia(fn ($page) => $page->where('run.accrual_missing', true));

    pdAs($this->hrManager)->post(route('hrm.payroll.post-accrual', $this->run))->assertSessionHasNoErrors();
    pdAs($this->hrManager)->post(route('hrm.payroll.post-accrual', $this->run))->assertSessionHasNoErrors();

    expect(pdAccruals()->count())->toBe(1)
        ->and(pdAccruals()->first()->journal_date->toDateString())->toBe('2026-09-30')
        ->and(pdSum('salaries_payable'))->toBe(-5000000);

    pdAs($this->hrManager)->get(route('hrm.payroll.index', ['year' => 2026, 'month' => 9]))
        ->assertInertia(fn ($page) => $page->where('run.accrual_missing', false));
});

it('says clearly when the missing entry falls in a closed period', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->owner, 'September close');

    pdAs($this->owner)->post(route('hrm.payroll.post-accrual', $this->run))->assertSessionHasErrors('accrual');

    expect(pdAccruals()->count())->toBe(0);
});

it('does not offer the entry when accounting is not switched on', function () {
    AccountMapping::query()->where('key', 'payroll.net_payable')->delete();

    expect(app(PostPayrollAccrual::class)->isMissingFor($this->run))->toBeFalse();
});

// --- 3. Correcting a wrong paid date -----------------------------------------

/** The incident: confirmed today although the bank paid on 30 Sep. */
function pdConfirmToday(): void
{
    app(ConfirmDisbursementBatch::class)->handle(test()->batch, test()->owner, Carbon::parse('2026-10-12'), null, test()->bank);
}

it('moves a confirmed batch to the bank date by reversing and re-posting', function () {
    pdConfirmToday();

    expect(pdSum('cash_at_bank', '2026-10-12'))->toBe(-5000000);

    pdAs($this->owner)->post(route('hrm.batches.correct-paid-date', $this->batch), [
        'paid_on' => '2026-09-30',
        'reason' => 'Bank statement shows 30 Sep',
    ])->assertSessionHasNoErrors();

    // ⚠⚠ 12 Oct nets to nothing: the wrong entries and their reversals both
    // sit there. 30 Sep carries the payment. The payable is clear.
    expect(pdSum('cash_at_bank', '2026-10-12'))->toBe(0)
        ->and(pdSum('salaries_payable', '2026-10-12'))->toBe(0)
        ->and(pdSum('cash_at_bank', '2026-09-30'))->toBe(-5000000)
        ->and(pdSum('cash_at_bank'))->toBe(-5000000)
        ->and(pdSum('salaries_payable'))->toBe(0);

    // Two originals, two reversals, two replacements: nothing edited, nothing deleted.
    expect(pdPaymentJournals()->count())->toBe(6)
        ->and(pdPaymentJournals()->whereNotNull('reverses_journal_id')->get()->every(fn ($j) => $j->journal_date->toDateString() === '2026-10-12'))->toBeTrue()
        ->and(PayslipPayment::pluck('paid_on')->map->toDateString()->unique()->all())->toBe(['2026-09-30'])
        ->and(pdPaymentJournals()->whereNotNull('reverses_journal_id')->first()->memo)->toContain('Bank statement shows 30 Sep');

    $log = Activity::where('description', 'salary paid date corrected')->get();
    expect($log)->toHaveCount(2)
        ->and($log->first()->properties['paid_on'])->toBe(['2026-10-12', '2026-09-30'])
        ->and($log->first()->properties['old_journal'])->not->toBeNull()
        ->and($log->first()->properties['new_journal'])->not->toBeNull();

    // The page shows the corrected date.
    pdAs($this->owner)->get(route('hrm.payroll.disbursement', $this->run))
        ->assertInertia(fn ($page) => $page->where('batches.0.paid_dates', ['2026-09-30'])->where('can_correct_paid_date', true));
});

it('is idempotent: correcting to the same date twice changes nothing more', function () {
    pdConfirmToday();

    foreach ([1, 2] as $attempt) {
        pdAs($this->owner)->post(route('hrm.batches.correct-paid-date', $this->batch), [
            'paid_on' => '2026-09-30', 'reason' => 'Bank statement',
        ])->assertSessionHasNoErrors();
    }

    expect(pdPaymentJournals()->count())->toBe(6)
        ->and(pdSum('cash_at_bank'))->toBe(-5000000);
});

it('refuses the correction to anyone but the owner', function () {
    pdConfirmToday();

    pdAs($this->hrManager)->post(route('hrm.batches.correct-paid-date', $this->batch), [
        'paid_on' => '2026-09-30', 'reason' => 'Bank statement',
    ])->assertForbidden();

    pdAs($this->hrManager)->get(route('hrm.payroll.disbursement', $this->run))
        ->assertInertia(fn ($page) => $page->where('can_correct_paid_date', false));

    expect(pdPaymentJournals()->count())->toBe(2);
});

it('refuses to move a payment into a closed period', function () {
    pdConfirmToday();
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->owner, 'September close');

    pdAs($this->owner)->post(route('hrm.batches.correct-paid-date', $this->batch), [
        'paid_on' => '2026-09-30', 'reason' => 'Bank statement',
    ])->assertSessionHasErrors('paid_on');

    expect(pdPaymentJournals()->count())->toBe(2)
        ->and(PayslipPayment::pluck('paid_on')->map->toDateString()->unique()->all())->toBe(['2026-10-12']);
});

it('requires a reason and refuses a future date', function () {
    pdConfirmToday();

    pdAs($this->owner)->post(route('hrm.batches.correct-paid-date', $this->batch), ['paid_on' => '2026-09-30'])
        ->assertSessionHasErrors('reason');
    pdAs($this->owner)->post(route('hrm.batches.correct-paid-date', $this->batch), ['paid_on' => '2026-10-20', 'reason' => 'x'])
        ->assertSessionHasErrors('paid_on');

    expect(pdPaymentJournals()->count())->toBe(2);
});

it('corrects one payslip payment on its own, and a later reversal takes out the right entry', function () {
    pdConfirmToday();
    $payment = PayslipPayment::orderBy('id')->first();

    pdAs($this->owner)->post(route('hrm.payslips.payments.correct-paid-date', $payment), [
        'paid_on' => '2026-09-30', 'reason' => 'Bank statement',
    ])->assertSessionHasNoErrors();

    expect($payment->fresh()->paid_on->toDateString())->toBe('2026-09-30')
        ->and(PayslipPayment::orderByDesc('id')->where('event_type', 'paid')->first()->paid_on->toDateString())->toBe('2026-10-12')
        ->and(pdSum('cash_at_bank', '2026-09-30'))->toBe(-3000000);

    // ⚠ The bounce after a correction must reverse the LIVE entry (30 Sep), not
    // the dead one that was already reversed.
    app(RecordPayslipPayment::class)->reverse($payment->fresh(), $this->owner, 'Returned by bank');

    expect(pdSum('cash_at_bank'))->toBe(-2000000);
});

it('refuses to correct a batch that belongs to another business', function () {
    pdConfirmToday();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co-pd']);
    $this->owner->businesses()->attach($other->id);

    // Route binding is tenant scoped: from the other business, this batch does
    // not exist at all.
    $status = $this->actingAs($this->owner)->withSession(['current_business_id' => $other->id])
        ->post(route('hrm.batches.correct-paid-date', $this->batch), ['paid_on' => '2026-09-30', 'reason' => 'x'])
        ->status();

    TenantScope::enter($this->business->id);

    expect($status)->toBeIn([403, 404])
        ->and(pdPaymentJournals()->count())->toBe(2);
});
