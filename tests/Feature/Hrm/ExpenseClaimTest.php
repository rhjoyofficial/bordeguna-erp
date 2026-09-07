<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\ExpenseClaimWorkflowSeeder;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * An employee spends their own money and gets it back.
 *
 * ⚠⚠ BOTH SETTLEMENT ROUTES ARE REAL (Joy, 27 Aug 2026): pay now from cash,
 * bank or an MFS wallet, or add it to the next salary. They end in different
 * documents and the claim must always be able to say which one happened.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Claim Co', 'slug' => 'claim-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['hrm.payroll.manage', 'purchasing.expense.record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Payroll', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.payroll.manage', 'purchasing.expense.record']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    // ⚠⚠ Seeded here, not only in the one test that names it. Without this row
    // every claim in every test would be raised into a workflow that does not
    // exist, which is exactly the state the module shipped in.
    (new ExpenseClaimWorkflowSeeder)->run();

    $this->finance = User::factory()->create();
    $this->finance->businesses()->attach($this->business->id);
    $this->finance->assignRole('Payroll');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'user_id' => $this->staffUser->id,
        'first_name' => 'Rakib',
        'last_name' => 'Hossain',
        'employment_type' => 'permanent',
        'status' => 'active',
    ]);

    $cashLedger = Account::create([
        'business_id' => $this->business->id,
        'code' => '1110', 'name' => 'Cash', 'type' => AccountType::Asset,
        'is_postable' => true, 'is_active' => true,
    ]);

    $this->head = Account::create([
        'business_id' => $this->business->id,
        'code' => '6230', 'name' => 'Fuel and Conveyance', 'type' => AccountType::Expense,
        'is_postable' => true, 'is_active' => true,
    ]);

    // ⚠ A payroll month must exist for the salary route: an adjustment belongs
    // to one, and the settlement names it rather than guessing.
    $this->period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026,
        'month' => 9,
        'start_date' => '2026-08-24',
        'end_date' => '2026-09-23',
    ]);

    $this->money = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Petty cash', 'type' => 'cash',
        'account_id' => $cashLedger->id, 'is_active' => true,
    ]);
});

function plantedCategory(): int
{
    \App\Modules\Hrm\Support\ExpenseCategories::ensureDefaults(
        app(\App\Modules\Foundation\Support\BusinessContext::class)->id(),
    );

    return (int) DB::table('expense_categories')->value('id');
}

function claimFor(int $employeeId, int $businessId, string $amount = '450.0000'): ExpenseClaim
{
    return ExpenseClaim::create([
        'business_id' => $businessId,
        'employee_id' => $employeeId,
        'claim_number' => ExpenseClaim::nextNumber($businessId),
        'spent_on' => '2026-08-26',
        'amount' => $amount,
        'purpose' => 'Courier to Savar',
    ]);
}

/**
 * The period's latest run, in whatever state the test needs it.
 *
 * ⚠ `version` matters: `latestRun()` orders by it, so a run written with the
 * default would not be the one the guard reads.
 */
function payrollRunFor(PayrollPeriod $period, PayrollStatus $status): PayrollRun
{
    return PayrollRun::create([
        'business_id' => $period->business_id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => $status,
        'employee_count' => 1,
        'total_net' => '25000.00',
    ]);
}

it('lets an employee raise a claim with a receipt, with no management permission', function () {
    $this->actingAs($this->staffUser)
        ->post('/hrm/my-expenses', [
            'expense_category_id' => plantedCategory(),
            'spent_on' => '2026-08-26',
            'amount' => '450',
            'purpose' => 'Courier to Savar',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $claim = ExpenseClaim::sole();

    expect($claim->employee_id)->toBe($this->employee->id)
        ->and($claim->attachments)->toHaveCount(1)
        // ⚠ Raised, not paid. A claim is a request until somebody settles it.
        ->and($claim->isSettled())->toBeFalse()
        ->and($claim->approvalRequest)->not->toBeNull();
});

it('refuses a claim with no receipt', function () {
    // ⚠⚠ The whole point of the feature: the office keeps the proof. Proof
    // asked for later is proof that often never arrives.
    $this->actingAs($this->staffUser)
        ->post('/hrm/my-expenses', [
            'expense_category_id' => plantedCategory(),
            'spent_on' => '2026-08-26',
            'amount' => '450',
            'purpose' => 'Courier to Savar',
        ])
        ->assertSessionHasErrors('receipt');

    expect(ExpenseClaim::count())->toBe(0);
});

it('refuses a claim dated in the future', function () {
    $this->actingAs($this->staffUser)
        ->post('/hrm/my-expenses', [
            'spent_on' => now()->addDay()->toDateString(),
            'amount' => '450',
            'purpose' => 'Courier',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertSessionHasErrors('spent_on');
});

it('shows an employee only their own claims', function () {
    $otherUser = User::factory()->create();
    $otherUser->businesses()->attach($this->business->id);
    $other = Employee::create([
        'employee_code' => 'BDG-000002', 'user_id' => $otherUser->id,
        'first_name' => 'Nazmul', 'last_name' => 'Haque',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    claimFor($this->employee->id, $this->business->id);
    claimFor($other->id, $this->business->id, '900.0000');

    $this->actingAs($this->staffUser)
        ->get('/hrm/my-expenses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('claims', 1));
});

it('refuses the settlement screen to an ordinary employee', function () {
    $this->actingAs($this->staffUser)->get('/hrm/expense-claims')->assertForbidden();
});

it('will not settle a claim nobody has approved', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->staffUser);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertSessionHasErrors();

    expect($claim->fresh()->isSettled())->toBeFalse()
        ->and(ExpenseVoucher::count())->toBe(0);
});

it('pays an approved claim now, and the voucher carries the receipt', function () {
    // ⚠ Deliberately a LEGACY claim - created without a category, the way
    // every claim before 3 Sep 2026 exists in production. The voucher route
    // must keep working for them; category claims settle via the payable.
    $claim = claimFor($this->employee->id, $this->business->id, '450');
    app(\App\Modules\Foundation\Actions\StoreAttachment::class)->handle(
        $claim,
        UploadedFile::fake()->image('receipt.jpg'),
        ExpenseClaim::EVIDENCE_COLLECTION,
        $this->staffUser,
    );
    $claim->submitForApproval($this->staffUser);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $claim->refresh();
    $voucher = ExpenseVoucher::sole();

    expect($claim->settlement_method)->toBe('payment')
        ->and($claim->expense_voucher_id)->toBe($voucher->id)
        // ⚠ The EMPLOYEE is the payee: the money left the business to reach
        // them, not the shop they bought from.
        ->and($voucher->payee())->toContain('Rakib')
        ->and($voucher->reference)->toBe($claim->claim_number)
        // ⚠⚠ The receipt follows the money, so the payment document is complete
        // on its own.
        ->and($voucher->attachments)->toHaveCount(1);
});

it('adds an approved claim to the next salary instead, moving no money today', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
            'payroll_period_id' => $this->period->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $claim->refresh();
    $adjustment = PayrollAdjustment::sole();

    expect($claim->settlement_method)->toBe('payroll')
        ->and($claim->payroll_adjustment_id)->toBe($adjustment->id)
        ->and($adjustment->type)->toBe(PayrollAdjustmentType::Reimbursement)
        ->and((float) $adjustment->amount)->toBe(450.0)
        // ⚠⚠ Nothing moved today: no voucher, no journal.
        ->and(ExpenseVoucher::count())->toBe(0);
});

it('refuses to settle the same claim twice', function () {
    // ⚠⚠ A second press is a second payment. Both routes are real money and
    // neither is recoverable by an undo button.
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $payload = ['method' => 'payroll', 'expense_account_id' => $this->head->id, 'payroll_period_id' => $this->period->id];

    $this->actingAs($this->finance)->post("/hrm/expense-claims/{$claim->id}/settle", $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->finance)->post("/hrm/expense-claims/{$claim->id}/settle", $payload)->assertSessionHasErrors();

    expect(PayrollAdjustment::count())->toBe(1);
});

it('needs a money account to pay now, but not to add to salary', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
        ])
        ->assertSessionHasErrors('money_account_id');
});

it('gives each claim its own number', function () {
    $first = claimFor($this->employee->id, $this->business->id);
    $second = claimFor($this->employee->id, $this->business->id);

    expect($first->claim_number)->toBe('EC-00001')
        ->and($second->claim_number)->toBe('EC-00002');
});

/**
 * ⚠⚠ THE TEST THAT WOULD HAVE CAUGHT THE WHOLE MODULE BEING DEAD.
 *
 * Every other test in this file reaches "approved" by writing the status onto
 * the approval request itself. That proves the settlement engine and proves
 * nothing at all about whether a human being can ever get a claim to that
 * state - and they could not: there was no `expense_claim` workflow, so the
 * request carried no stage, the inbox never listed it, nobody was notified and
 * there was no approve route to press. The employee saw "sent for approval" and
 * the claim sat there for ever.
 *
 * This one refuses every shortcut: raise it through the screen, find it in the
 * approver's own inbox, press the URL the inbox itself gives, and only then pay.
 */
it('carries a claim from the employee to the approver inbox to the money, with no shortcuts', function () {
    $this->actingAs($this->staffUser)
        ->post('/hrm/my-expenses', [
            'expense_category_id' => plantedCategory(),
            'spent_on' => '2026-08-26',
            'amount' => '450',
            'purpose' => 'Courier to Savar',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertSessionHasNoErrors();

    $claim = ExpenseClaim::sole();

    // ⚠ The employee who raised it must NOT be able to approve their own claim.
    $this->actingAs($this->staffUser)
        ->post("/hrm/expense-claims/{$claim->id}/approve")
        ->assertForbidden();

    // It reaches the approver's inbox, with a button that goes somewhere.
    $inbox = $this->actingAs($this->finance)->get('/approvals');
    $inbox->assertOk();

    $row = collect($inbox->viewData('page')['props']['requests'])
        ->firstWhere('type', 'Expense claim');

    expect($row)->not->toBeNull()
        ->and($row['approve_url'])->toBe(route('hrm.expense-claims.approve', $claim))
        ->and($row['summary'])->toContain('EC-00001')
        ->and($row['summary'])->toContain('Courier to Savar')
        // ⚠ The receipt count is on the CARD so nobody approves blind - it
        // moved from the summary string into the labelled details when the
        // inbox became decision cards (31 Aug 2026).
        ->and(collect($row['details'])->firstWhere('label', 'Receipts')['value'])->toContain('1 attached');

    // Press exactly what the inbox offered.
    $this->actingAs($this->finance)->post($row['approve_url'])->assertSessionHasNoErrors();

    expect($claim->fresh()->isApproved())->toBeTrue();

    // ⚠⚠ Approved is still not paid. The money needs a second, deliberate act.
    expect($claim->fresh()->isSettled())->toBeFalse();

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertSessionHasNoErrors();

    expect($claim->fresh()->isSettled())->toBeTrue()
        ->and(ExpenseVoucher::count())->toBe(1);
});

it('lets an approver turn a claim down without destroying the record of it', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->staffUser);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/reject", ['note' => 'Personal, not company spending.'])
        ->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->isApproved())->toBeFalse()
        ->and($claim->state())->toBe('Rejected')
        // ⚠ The claim itself survives. It is the proof the employee asked.
        ->and($claim->exists)->toBeTrue()
        ->and($claim->isSettled())->toBeFalse();

    // And a rejected claim can never be paid by going straight to settlement.
    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertSessionHasErrors();

    expect(ExpenseVoucher::count())->toBe(0);
});

/**
 * ⚠⚠ THE GUARD THAT PROTECTS A MONTH ALREADY PAID. A reimbursement added to a
 * disbursed run is a line in a payslip somebody has already signed off and
 * already handed over - the ERP would show a net figure nobody was ever paid.
 */
it('refuses to add a claim to a salary month that has already been paid', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    payrollRunFor($this->period, PayrollStatus::Disbursed);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
            'payroll_period_id' => $this->period->id,
        ])
        ->assertSessionHasErrors('payroll_period_id');

    expect(PayrollAdjustment::count())->toBe(0)
        ->and($claim->fresh()->isSettled())->toBeFalse();
});

/**
 * ⚠⚠ THE OTHER HALF, AND IT IS NOT SYMMETRY FOR ITS OWN SAKE. A rejected run
 * means the month is VOID AND UNPAID: a fresh version is still coming, and the
 * reimbursement belongs in it. A guard written as "anything final" would have
 * locked Joy out of the very month he was trying to fix, which is exactly how
 * the rejected-run dead end bit the August payroll twice.
 */
it('still adds a claim to a month whose payroll was rejected, because nobody was paid', function () {
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    payrollRunFor($this->period, PayrollStatus::Rejected);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
            'payroll_period_id' => $this->period->id,
        ])
        ->assertSessionHasNoErrors();

    expect(PayrollAdjustment::count())->toBe(1);
});

it('will not add a claim to salary without naming the month', function () {
    // ⚠⚠ Guessing "the current month" would put a reimbursement into a run that
    // may already be calculated, approved or disbursed - money appearing in a
    // month somebody has already signed off.
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
        ])
        ->assertSessionHasErrors('payroll_period_id');
});

it('puts a claim settled to salary where payroll will actually pay it', function () {
    // ⚠⚠ THE DEFECT THIS TEST EXISTS FOR. Settling to salary used to create a
    // plain adjustment: no approval request of its own, and no source key. But
    // `scopePayable` pays a MANUAL adjustment only once its own approval is
    // approved, and a GENERATED one only when it has a source key and no
    // approval. This row was neither, so payroll skipped it forever - and it
    // could not be approved afterwards either, because the approve route 404s
    // when there is no approval request. The claim looked settled on screen and
    // the employee was never paid.
    //
    // The old assertions could not see any of that: they checked the row
    // existed. Whether payroll would PAY it is the only question that matters.
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
            'payroll_period_id' => $this->period->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $adjustment = PayrollAdjustment::sole();

    expect($adjustment->source_key)->toBe("expense_claim:{$claim->id}")
        ->and(PayrollAdjustment::payable()->pluck('id')->all())->toBe([$adjustment->id]);
});

it('never adds the same claim to a salary twice, even by two routes at once', function () {
    // The unique index on (business_id, source_key) is the real guarantee; a
    // duplicated reimbursement is money paid twice.
    $claim = claimFor($this->employee->id, $this->business->id);
    $claim->submitForApproval($this->finance);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $post = fn () => $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payroll',
            'expense_account_id' => $this->head->id,
            'payroll_period_id' => $this->period->id,
        ]);

    $post()->assertSessionHasNoErrors();
    $post();

    expect(PayrollAdjustment::count())->toBe(1);
});

/**
 * ⛔⛔ THE VOUCHER IS THE ONE PIECE OF PAPER THE EMPLOYEE KEEPS.
 *
 * The print route shipped on 7 Sep with no test at all, and it was handing the
 * page `$claim->expenseAccount->label()` under BOTH `category` and
 * `charged_to`. `Account::label()` is "6230 - Fuel and Conveyance", so the copy
 * given to the person being paid printed a chart-of-accounts code - on the one
 * document whose whole premise is that an employee never has to meet the chart.
 *
 * ⚠ The claim LIST in the same controller already had this right. This pins the
 * two together so they cannot drift apart again: the category is what both
 * audiences recognise, and the account stays available for the office.
 */
it('prints a settled claim with the category the employee chose, not the chart code', function () {
    $this->actingAs($this->staffUser)
        ->post('/hrm/my-expenses', [
            'expense_category_id' => plantedCategory(),
            'spent_on' => '2026-08-26',
            'amount' => '450',
            'purpose' => 'Courier to Savar',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertSessionHasNoErrors();

    $claim = ExpenseClaim::sole();
    $this->actingAs($this->finance)->post("/hrm/expense-claims/{$claim->id}/approve")->assertSessionHasNoErrors();

    // ⚠ Approved is not paid, and a voucher for money that has not moved is a
    // document that says something untrue.
    $this->actingAs($this->finance)
        ->get("/hrm/expense-claims/{$claim->id}/print")
        ->assertNotFound();

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($this->finance)->get("/hrm/expense-claims/{$claim->id}/print");
    $response->assertOk();

    $printed = $response->viewData('page')['props']['claim'];
    $categoryName = DB::table('expense_categories')->where('id', $claim->expense_category_id)->value('name');

    expect($printed['category'])->toBe($categoryName)
        ->and($printed['category'])->not->toContain($this->head->code)
        // The account is still on the payload for the office, and the journal
        // numbers are there so somebody can walk from the paper to the ledger.
        ->and($printed['charged_to'])->toContain($this->head->code)
        ->and($printed['employee'])->toContain('Rakib')
        ->and($printed['method'])->toBe('Paid directly');
});

/**
 * ⚠ `expense_category_id` is NULLABLE and was only added on 4 Sep 2026, so a
 * claim raised before that carries no category at all. The voucher must still
 * say what the money was charged to rather than printing a blank line, which is
 * why the page falls back to the account.
 *
 * ⚠⚠ Checked against production on 7 Sep rather than assumed: all three live
 * claims (EC-00001, EC-00003, EC-00004) DO carry a category today, and EC-00002
 * no longer exists. The fallback is therefore untested by the live data, which
 * is precisely why it is tested here.
 */
it('prints a legacy claim with no category, falling back to the account', function () {
    $claim = claimFor($this->employee->id, $this->business->id, '450');
    $claim->submitForApproval($this->staffUser);
    $claim->approvalRequest->update(['status' => ApprovalStatus::Approved]);

    $this->actingAs($this->finance)
        ->post("/hrm/expense-claims/{$claim->id}/settle", [
            'method' => 'payment',
            'expense_account_id' => $this->head->id,
            'money_account_id' => $this->money->id,
        ])
        ->assertSessionHasNoErrors();

    $printed = $this->actingAs($this->finance)
        ->get("/hrm/expense-claims/{$claim->id}/print")
        ->viewData('page')['props']['claim'];

    expect($printed['category'])->toBeNull()
        ->and($printed['charged_to'])->toContain('6230');
});
