<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Inventory\Models\CropWriteOff;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Support\SalesOrderActions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PeopleWorld;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026; FINAL-PLAN 3.4 "Any waiting
 * request: Withdraw", 3.5 "Approval request: Withdraw"): every approvable type
 * says what withdrawing means, and the sender pulls it back from the approvals
 * inbox. Never a rejection: the request is handed back (Returned).
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;
    $this->p = PeopleWorld::make($this->w);
    $this->sender = $this->p->staff;
});

afterEach(fn () => Carbon::setTestNow());

function withdrawIt(object $test, User $by, $document, string $reason = 'Sent it by mistake')
{
    return $test->actingAs($by)->post(route('approvals.withdraw', $document->fresh()->approvalRequest), ['reason_text' => $reason]);
}

it('every approvable type opts in to Withdraw, apart from the named exceptions', function () {
    // PayrollRun has its own Reopen and Cancel (three approvals, an accrual);
    // MessageCampaign is the CRM builder's (w3-crm).
    $exceptions = ['PayrollRun', 'MessageCampaign'];
    $missing = [];

    foreach (File::glob(app_path('Modules/*/Models/*.php')) as $file) {
        $module = basename(dirname($file, 2));
        $class = 'App\\Modules\\'.$module.'\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || ! in_array(Approvable::class, class_uses_recursive($class), true)) {
            continue;
        }

        if (! is_subclass_of($class, WithdrawsFromApproval::class) && ! in_array(class_basename($class), $exceptions, true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([]);
});

it('withdraws a waiting leave request: closed as cancelled, never rejected, nothing charged', function () {
    (new LeaveTypeSeeder)->run();
    $leave = LeaveRequest::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id,
        'leave_type_id' => LeaveType::query()->value('id'), 'start_date' => '2026-10-20', 'end_date' => '2026-10-21',
        'days' => 2, 'reason' => 'Family', 'requested_by' => $this->sender->id,
    ]);
    $leave->submitForApproval($this->sender);

    // Somebody else cannot.
    withdrawIt($this, $this->w->owner, $leave)->assertSessionHasErrors('reason_text');
    // A reason is required on the server.
    withdrawIt($this, $this->sender, $leave, 'no')->assertSessionHasErrors('reason_text');

    withdrawIt($this, $this->sender, $leave)->assertSessionHasNoErrors();

    $leave->refresh();
    expect($leave->approvalRequest->status)->toBe(ApprovalStatus::Returned)
        ->and($leave->isCancelled())->toBeTrue()
        ->and($leave->cancel_reason)->toContain('Withdrawn before approval')
        ->and($leave->approvalRequest->review_note)->toContain('Withdrawn by')
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'withdrawn')->count())->toBe(1);

    // A double click finds it no longer waiting.
    withdrawIt($this, $this->sender, $leave)->assertSessionHasErrors('reason_text');
});

it('withdraws a waiting movement as cancelled', function () {
    $movement = Movement::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id,
        'type' => MovementType::FieldDuty, 'movement_date' => '2026-10-20', 'reason' => 'Market visit', 'requested_by' => $this->sender->id,
    ]);
    $movement->submitForApproval($this->sender);

    withdrawIt($this, $this->sender, $movement)->assertSessionHasNoErrors();

    expect($movement->fresh()->isCancelled())->toBeTrue();
});

it('withdraws a waiting expense claim back to a draft the employee can send again', function () {
    $claim = ExpenseClaim::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id,
        'claim_number' => ExpenseClaim::nextNumber($this->w->business->id), 'spent_on' => '2026-10-05', 'amount' => '300',
        'created_by' => $this->sender->id,
    ]);
    $claim->forceFill(['submitted_at' => now()])->save();
    $claim->submitForApproval($this->sender);

    withdrawIt($this, $this->sender, $claim)->assertSessionHasNoErrors();

    $claim->refresh();
    expect($claim->isDraft())->toBeTrue()
        ->and($claim->approvalRequest->status)->toBe(ApprovalStatus::Returned);

    // Sent again on the same request row.
    $claim->resubmitForApproval($this->sender);
    expect($claim->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($claim->fresh()->approvalRequest->id)->toBe($claim->approvalRequest->id);
});

it('withdraws a waiting payroll adjustment and a waiting staff loan as cancelled', function () {
    $adjustment = PayrollAdjustment::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id,
        'payroll_period_id' => $this->p->period(2026, 10)->id, 'type' => PayrollAdjustmentType::Addition,
        'amount' => '800', 'reason' => 'Night duty', 'requested_by' => $this->sender->id,
    ]);
    $adjustment->submitForApproval($this->sender);

    $loan = Loan::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'reference' => 'LOAN-0001',
        'type' => LoanType::Advance, 'status' => LoanStatus::Pending, 'principal' => '6000', 'installment_amount' => '2000',
        'installment_count' => 3, 'start_year' => 2026, 'start_month' => 11, 'reason' => 'Medical', 'requested_by' => $this->sender->id,
    ]);
    $loan->submitForApproval($this->sender);

    withdrawIt($this, $this->sender, $adjustment)->assertSessionHasNoErrors();
    withdrawIt($this, $this->sender, $loan)->assertSessionHasNoErrors();

    expect($adjustment->fresh()->isCancelled())->toBeTrue()
        ->and(PayrollAdjustment::query()->approved()->count())->toBe(0)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Cancelled);
});

it('withdraws a waiting sales order back to a draft; editing it sends it for approval again', function () {
    $order = app(CreateSalesOrder::class)->handle(
        customer: $this->s->customer,
        lines: [['product' => $this->s->honey, 'quantity' => '5', 'unit_price' => '850']],
        createdBy: $this->s->cashier,
        warehouse: $this->s->warehouse,
        orderedAt: '2026-10-10',
        selfApprove: false,
    );
    expect($order->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    withdrawIt($this, $this->s->cashier, $order)->assertSessionHasNoErrors();
    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Returned);

    $form = SalesOrderActions::form($order->fresh(), false);
    $form['lines'] = array_map(fn ($l) => ['id' => $l['id'], 'product_id' => $l['product_id'], 'quantity' => '6', 'unit_price' => $l['unit_price'], 'description' => $l['description']], $form['lines']);
    unset($form['id'], $form['order_number'], $form['mode']);

    $this->actingAs($this->s->cashier)
        ->put(route('sales.orders.update', $order), array_replace($form, ['address' => null]))
        ->assertSessionHasNoErrors();

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending);
});

it('withdraws a credit terms request, an HR correction request and a crop write-off', function () {
    $terms = CreditTermsRequest::create([
        'business_id' => $this->w->business->id, 'request_number' => 'CTR-0001', 'requested_by' => $this->sender->id,
    ]);
    $terms->submitForApproval($this->sender);

    $hr = HrCorrectionRequest::create([
        'business_id' => $this->w->business->id, 'action' => HrCorrectionRequest::CANCEL_MOVEMENT,
        'subject_type' => 'movement', 'subject_id' => 1, 'subject_label' => 'Movement #1', 'reason_text' => 'Wrong day', 'why' => 'Payroll is approved',
        'fingerprint' => 'x', 'status' => 'pending', 'pending_key' => 'cancel_movement:movement:1', 'requested_by' => $this->sender->id,
    ]);
    $hr->submitForApproval($this->sender);

    $crop = Account::query()->where('code', '4910')->sole();
    $writeOff = CropWriteOff::create([
        'business_id' => $this->w->business->id, 'number' => 'CWO-0001', 'written_off_on' => '2026-10-15',
        'crop_account_id' => $crop->id, 'loss_account_id' => $crop->id, 'amount_paisa' => 50000,
        'reason_text' => 'Flood', 'status' => CropWriteOff::WAITING, 'created_by' => $this->sender->id,
    ]);
    $writeOff->submitForApproval($this->sender);

    withdrawIt($this, $this->sender, $terms)->assertSessionHasNoErrors();
    withdrawIt($this, $this->sender, $hr)->assertSessionHasNoErrors();
    withdrawIt($this, $this->sender, $writeOff)->assertSessionHasNoErrors();

    expect($terms->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Returned)
        ->and($hr->fresh()->status)->toBe('withdrawn')
        ->and($hr->fresh()->pending_key)->toBeNull()
        ->and($writeOff->fresh()->status)->toBe(CropWriteOff::WITHDRAWN)
        ->and($writeOff->fresh()->journal_id)->toBeNull();
});

it('lists what the sender sent with what withdrawing means', function () {
    $movement = Movement::create([
        'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id,
        'type' => MovementType::FieldDuty, 'movement_date' => '2026-10-20', 'reason' => 'Market visit', 'requested_by' => $this->sender->id,
    ]);
    $movement->submitForApproval($this->sender);

    $this->actingAs($this->sender)->get(route('approvals.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mine.0.withdraw_blocked', null)
            ->where('mine.0.withdraw_outcome', 'cancelled'));
});
