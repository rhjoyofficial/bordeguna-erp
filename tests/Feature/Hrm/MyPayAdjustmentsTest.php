<?php

use App\Modules\Hrm\Actions\CancelPayrollAdjustment;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Notifications\PayAdjustmentDecidedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ My deductions (Joy, 27 Oct 2026): "Office gave penalty to Nazmul ... does
 * Nazmul can see why his salary deduct". He hears it when it is approved or
 * cancelled, and My payslips lists what is coming on his next salary.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);
    (new PayrollInputWorkflowSeeder)->run();
    Notification::fake();
});

afterEach(fn () => Carbon::setTestNow());

function pendingAdjustment(PayrollPeriod $period, PayrollAdjustmentType $type = PayrollAdjustmentType::Penalty, string $amount = '888', ?Employee $employee = null): PayrollAdjustment
{
    $adjustment = PayrollAdjustment::create([
        'business_id' => test()->w->business->id,
        'employee_id' => ($employee ?? test()->p->employee)->id,
        'payroll_period_id' => $period->id,
        'type' => $type,
        'amount' => $amount,
        'reason' => 'Late three days without notice',
        'requested_by' => test()->w->financeA->id,
    ]);
    $adjustment->submitForApproval(test()->w->financeA);

    return $adjustment->fresh();
}

function sentSummaries(): array
{
    return Notification::sent(test()->p->staff, PayAdjustmentDecidedNotification::class)
        ->map(fn (PayAdjustmentDecidedNotification $n) => $n->summary)->values()->all();
}

it('tells the employee when a penalty on his pay is approved, with the reason and who decided', function () {
    $penalty = pendingAdjustment($this->p->period(2026, 11));

    Notification::assertNothingSentTo($this->p->staff);

    $this->actingAs($this->w->owner)
        ->post(route('hrm.adjustments.approve', $penalty))
        ->assertSessionHasNoErrors();

    expect(sentSummaries())->toBe([
        "A penalty of Tk 888.00 will be deducted from your November 2026 salary. Reason: Late three days without notice. Decided by {$this->w->owner->name}.",
    ]);
});

it('says an addition will be added to his salary', function () {
    $addition = pendingAdjustment($this->p->period(2026, 11), PayrollAdjustmentType::Addition, '1500');

    $this->actingAs($this->w->owner)->post(route('hrm.adjustments.approve', $addition))->assertSessionHasNoErrors();

    expect(sentSummaries()[0])->toStartWith('An addition of Tk 1,500.00 will be added to your November 2026 salary.');
});

it('does not tell anyone while an adjustment is pending or when a pending one is rejected', function () {
    $penalty = pendingAdjustment($this->p->period(2026, 11));

    $this->actingAs($this->w->owner)->post(route('hrm.adjustments.reject', $penalty))->assertSessionHasNoErrors();

    Notification::assertNothingSentTo($this->p->staff);
});

it('tells the employee when an approved penalty is cancelled', function () {
    $penalty = $this->p->approvedAdjustment($this->p->period(2026, 11), PayrollAdjustmentType::Penalty, '888');

    app(CancelPayrollAdjustment::class)->handle($penalty, $this->w->financeB, $this->p->reason('Wrong employee picked'));

    expect(sentSummaries())->toBe([
        "The penalty of Tk 888.00 on your November 2026 salary was cancelled, so it will not be deducted. Reason: Wrong employee picked. Cancelled by {$this->w->financeB->name}.",
    ]);
});

it('says where the money comes back when the month was already locked', function () {
    $august = $this->p->period(2026, 8);
    PayrollRun::create(['business_id' => $this->w->business->id, 'payroll_period_id' => $august->id, 'version' => 1, 'status' => PayrollStatus::Locked]);
    $penalty = $this->p->approvedAdjustment($august, PayrollAdjustmentType::Penalty, '888');

    app(CancelPayrollAdjustment::class)->handle($penalty, $this->w->financeB, $this->p->reason('Wrong employee picked'));

    expect(sentSummaries()[0])->toContain('was cancelled. Tk 888.00 will be added to your September 2026 salary to give it back.');
});

it('lists his approved adjustments coming on the next salary, with reasons, and nobody else\'s', function () {
    $november = $this->p->period(2026, 11);
    $penalty = $this->p->approvedAdjustment($november, PayrollAdjustmentType::Penalty, '888');
    $this->p->approvedAdjustment($november, PayrollAdjustmentType::Addition, '500');
    pendingAdjustment($november);

    $other = Employee::create([
        'business_id' => $this->w->business->id, 'employee_code' => 'BDG-000099',
        'first_name' => 'Nazmul', 'last_name' => 'Other', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    $this->p->approvedAdjustment($november)->forceFill(['employee_id' => $other->id])->save();

    $this->actingAs($this->p->staff)->get(route('hrm.my-payslips'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/my-payslips')
            ->has('upcoming_adjustments', 2)
            ->where('upcoming_adjustments.0.id', $penalty->id)
            ->where('upcoming_adjustments.0.month', 'November 2026')
            ->where('upcoming_adjustments.0.type', 'penalty')
            ->where('upcoming_adjustments.0.amount', -888)
            ->where('upcoming_adjustments.0.reason', 'Night duty')
            ->where('upcoming_adjustments.0.decided_by', $this->w->owner->name)
            ->where('upcoming_adjustments.1.amount', 500)
            ->where('upcoming_adjustments.1.type', 'addition'));

    $this->actingAs($this->p->staff)->get(route('hrm.my-profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('upcoming_adjustments', 2));
});

it('moves a line off the coming list once that payroll is finished, since it is on the payslip then', function () {
    $november = $this->p->period(2026, 11);
    $this->p->approvedAdjustment($november, PayrollAdjustmentType::Penalty, '888');
    $run = PayrollRun::create(['business_id' => $this->w->business->id, 'payroll_period_id' => $november->id, 'version' => 1, 'status' => PayrollStatus::Calculated]);

    $this->actingAs($this->p->staff)->get(route('hrm.my-payslips'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('upcoming_adjustments', 1));

    $run->forceFill(['status' => PayrollStatus::Approved])->save();

    $this->actingAs($this->p->staff)->get(route('hrm.my-payslips'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('upcoming_adjustments', 0));
});

it('drops a cancelled adjustment from the coming list', function () {
    $penalty = $this->p->approvedAdjustment($this->p->period(2026, 11), PayrollAdjustmentType::Penalty, '888');
    app(CancelPayrollAdjustment::class)->handle($penalty, $this->w->financeB, $this->p->reason('Wrong employee picked'));

    $this->actingAs($this->p->staff)->get(route('hrm.my-payslips'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('upcoming_adjustments', 0));
});
