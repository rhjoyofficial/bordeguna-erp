<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\ExpenseClaimWorkflowSeeder;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ExpenseClaim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The accountant's expense-claim structure (Joy's file, 3 Sep 2026):
 *
 * The employee gives five things - date, amount, CATEGORY from a dropdown,
 * a short purpose, and evidence. Behind each category sits a COA mapping
 * the accountant controls; the employee never learns account codes.
 *
 * The accounting follows the file exactly: AT FINAL APPROVAL the expense
 * is real - Dr the category's account, Cr Employee Reimbursements Payable,
 * with the employee as party. Settlement later clears the payable against
 * the money account. Cash never moved at approval, and the expense never
 * waits for the cash.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Claim Co', 'slug' => 'claim-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new ExpenseClaimWorkflowSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 15:00:00');
    Storage::fake('local');

    // The anchor every payroll posting checks: accounting is ON.
    AccountMapping::create([
        'business_id' => $this->business->id,
        'key' => 'payroll.net_payable',
        'account_id' => Account::query()->where('code', '2130')->firstOrFail()->id,
        'effective_from' => '2000-01-01',
    ]);

    $this->approver = User::factory()->create();
    $this->approver->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->approver->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'user_id' => $this->staffUser->id,
        'employee_code' => 'BDG-000014',
        'first_name' => 'Musa', 'last_name' => 'Islam',
        'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-01', 'is_primary' => true,
    ]);

    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $this->asStaff = fn () => $this->actingAs($this->staffUser)
        ->withSession(['current_business_id' => $this->business->id]);
    $this->asApprover = fn () => $this->actingAs($this->approver)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

function categoryId(string $name): int
{
    return (int) DB::table('expense_categories')->where('name', 'like', $name.'%')->value('id');
}

// ⚠ The category is named, not resolved by the caller: ids only exist
// after the first visit plants the defaults, and an id read too early is 0.
function submitClaim(string $category = 'Mobile'): ExpenseClaim
{
    (test()->asStaff)()->get(route('hrm.my-expenses')); // plants default categories

    (test()->asStaff)()->post(route('hrm.my-expenses.store'), [
        'spent_on' => '2026-09-03',
        'amount' => '547',
        'expense_category_id' => categoryId($category),
        'purpose' => 'Laladia SIM - minute purchase',
        'receipt' => UploadedFile::fake()->image('recharge.png', 600, 800),
    ])->assertSessionHasNoErrors();

    return ExpenseClaim::query()->latest('id')->firstOrFail();
}

it('plants the default categories with their COA mapping, and requires one on a claim', function () {
    ($this->asStaff)()->get(route('hrm.my-expenses'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/expense-claims/mine')
            ->where('categories', fn ($categories) => collect($categories)->contains(
                fn ($category) => str_starts_with($category['name'], 'Mobile'))));

    // The mapping behind the Mobile category is 6055 Mobile and Telephone.
    $mobile = DB::table('expense_categories')->where('id', categoryId('Mobile'))->first();
    expect(Account::find($mobile->account_id)->code)->toBe('6055');

    // A claim without a category is refused - free text alone is over.
    ($this->asStaff)()->post(route('hrm.my-expenses.store'), [
        'spent_on' => '2026-09-03', 'amount' => '100', 'purpose' => 'Something',
        'receipt' => UploadedFile::fake()->image('r.png'),
    ])->assertSessionHasErrors('expense_category_id');
});

it('posts Dr category account, Cr employee payable at FINAL approval - the 547 exactly', function () {
    $claim = submitClaim();

    expect(DB::table('journals')->count())->toBe(0);

    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim))
        ->assertSessionHasNoErrors();

    $claim->refresh();
    expect($claim->approval_journal_id)->not->toBeNull();

    $telephone = Account::query()->where('code', '6055')->firstOrFail();
    $payable = Account::bySlug('employee_reimbursements_payable');

    $lines = DB::table('journal_lines')->where('journal_id', $claim->approval_journal_id)->get();
    expect((int) $lines->firstWhere('account_id', $telephone->id)->amount_minor)->toBe(54700)
        ->and((int) $lines->firstWhere('account_id', $payable->id)->amount_minor)->toBe(-54700);
});

it('settles by clearing the payable against the till, never expensing twice', function () {
    $claim = submitClaim();
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim));

    ($this->asApprover)()->post(route('hrm.expense-claims.settle', $claim->id), [
        'method' => 'payment',
        'money_account_id' => $this->till->id,
    ])->assertSessionHasNoErrors();

    $claim->refresh();
    expect($claim->settled_at)->not->toBeNull();

    $payable = Account::bySlug('employee_reimbursements_payable');
    $telephone = Account::query()->where('code', '6055')->firstOrFail();

    // The payable nets to zero, the expense stands ONCE, cash went down.
    expect((int) DB::table('journal_lines')->where('account_id', $payable->id)->sum('amount_minor'))->toBe(0)
        ->and((int) DB::table('journal_lines')->where('account_id', $telephone->id)->sum('amount_minor'))->toBe(54700)
        ->and((int) DB::table('journal_lines')->whereNotNull('money_account_id')->sum('amount_minor'))->toBe(-54700);
});

it('takes the salary route for a claim already expensed at approval, without expensing it twice', function () {
    // ⚠⚠ THIS TEST USED TO ASSERT THE OPPOSITE. Until 7 Sep 2026 the salary
    // route was REFUSED for a claim booked at approval, because payroll posts a
    // `reimbursement` earning against an EXPENSE account and the cost would have
    // been recorded twice.
    //
    // Joy asked for both routes on every claim, so the refusal was replaced by a
    // separate adjustment type whose earning code maps to the PAYABLE. What the
    // test guards is unchanged - the cost may only be counted once - but the way
    // it is guaranteed is now a mapping rather than a locked door.
    $claim = submitClaim();
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim));

    ($this->asApprover)()->post(route('hrm.expense-claims.settle', $claim->id), [
        'method' => 'payroll', 'payroll_period_id' => \App\Modules\Hrm\Models\PayrollPeriod::forMonth(2026, 9)->id,
    ])->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->settled_at)->not->toBeNull()
        ->and($claim->settlement_method)->toBe(ExpenseClaim::SETTLE_PAYROLL)
        ->and($claim->payroll_adjustment_id)->not->toBeNull();

    $adjustment = \App\Modules\Hrm\Models\PayrollAdjustment::findOrFail($claim->payroll_adjustment_id);

    // ⛔ The line that decides whether the cost is counted twice. `Reimbursement`
    // maps to an expense account; `ExpenseClaimSettlement` maps to the payable.
    expect($adjustment->type)->toBe(\App\Modules\Hrm\Enums\PayrollAdjustmentType::ExpenseClaimSettlement);

    // The expense stands exactly ONCE, from the approval posting. Nothing about
    // choosing the salary route added a second one.
    $telephone = Account::query()->where('code', '6055')->firstOrFail();
    expect((int) DB::table('journal_lines')->where('account_id', $telephone->id)->sum('amount_minor'))->toBe(54700);

    // ⚠ The payable is still outstanding, and that is correct: payroll clears it
    // when the salary is actually posted, not when the claim is filed against it.
    $payable = Account::bySlug('employee_reimbursements_payable');
    expect((int) DB::table('journal_lines')->where('account_id', $payable->id)->sum('amount_minor'))->toBe(-54700);
});

it('shows the accountant the mapping screen and remaps a category, but hides it from staff', function () {
    ($this->asApprover)()->get(route('hrm.expense-categories'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/expense-categories')
            ->where('categories', fn ($categories) => collect($categories)->contains(
                fn ($category) => $category['name'] === 'Other Approved Business Expense' && $category['is_exception'] === true))
            ->where('accounts', fn ($accounts) => count($accounts) > 0));

    // ⚠ The screen a claimant must never reach: the mapping is the
    // accountant's, and staff who can pick the account can pick the answer.
    ($this->asStaff)()->get(route('hrm.expense-categories'))->assertForbidden();

    $stationery = Account::query()->where('code', '6058')->firstOrFail();
    ($this->asApprover)()->patch(route('hrm.expense-categories.update', categoryId('Mobile')), [
        'account_id' => $stationery->id,
    ])->assertSessionHasNoErrors();

    expect((int) DB::table('expense_categories')->where('id', categoryId('Mobile'))->value('account_id'))
        ->toBe($stationery->id);
});

it('remaps only what comes next - a posted journal is history', function () {
    $claim = submitClaim();
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim));

    $telephone = Account::query()->where('code', '6055')->firstOrFail();
    $stationery = Account::query()->where('code', '6058')->firstOrFail();

    ($this->asApprover)()->patch(route('hrm.expense-categories.update', categoryId('Mobile')), [
        'account_id' => $stationery->id,
    ]);

    // The journal that was posted yesterday does not move because somebody
    // changed a dropdown today.
    expect((int) DB::table('journal_lines')
        ->where('journal_id', $claim->fresh()->approval_journal_id)
        ->where('account_id', $telephone->id)->sum('amount_minor'))->toBe(54700)
        ->and(DB::table('journal_lines')
            ->where('journal_id', $claim->fresh()->approval_journal_id)
            ->where('account_id', $stationery->id)->exists())->toBeFalse();
});

it('puts the category, the account it will hit and the exception warning on the approver card', function () {
    $claim = submitClaim('Other Approved');

    $card = app(\App\Support\ApprovalCards::class)->present($claim->fresh()->approvalRequest);
    $details = collect($card['details']);

    // Joy's 31 Aug rule: the card carries what saying yes will DO. Approval
    // now posts a journal, so the account it lands in belongs on the card.
    expect($details->firstWhere('label', 'Category')['value'])->toStartWith('Other Approved Business Expense')
        ->and($details->firstWhere('label', 'Books')['value'])->toContain('6601')
        ->and($details->firstWhere('label', 'Books')['value'])->toContain('Employee Reimbursements Payable')
        ->and($details->firstWhere('label', 'Category')['value'])->toContain('look twice');
});

it('carries the category onto the claim rows both audiences read', function () {
    $claim = submitClaim();

    ($this->asStaff)()->get(route('hrm.my-expenses'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('claims.0.category', fn ($value) => str_starts_with((string) $value, 'Mobile')));

    ($this->asApprover)()->get(route('hrm.expense-claims.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('claims.0.category', fn ($value) => str_starts_with((string) $value, 'Mobile'))
            // Before approval nothing is booked, so the old pick-a-head form
            // is still what the screen shows.
            ->where('claims.0.is_booked', false));

    // ⚠ After approval the screen must switch: no expense head to pick, no
    // salary route - just paying off what the books already say is owed.
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim));

    ($this->asApprover)()->get(route('hrm.expense-claims.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('claims.0.is_booked', true)
            ->where('claims.0.expense_account', fn ($value) => str_contains((string) $value, '6055')));
});

/**
 * ⚠⚠ THE SPEC'S OWN TABLE, category by category (employee-expense.txt §2 and
 * §4). Its §5 reason is reporting: "September 2026 — Telephone & Internet
 * Expense = X", drilled down to employee, date, purpose, amount, evidence.
 * Folding Courier, Travel, Packaging and Software into Miscellaneous would
 * leave every one of those questions unanswerable, so each has its own head.
 */
it('maps every category the master chart lists to the account it names', function () {
    ($this->asStaff)()->get(route('hrm.my-expenses'));

    // Section 19 of the Master Expense Chart of Accounts, in full. The point of
    // the list is that a staff member reads it and recognises their own spending
    // without being taught a single account code.
    $expected = [
        'Conveyance' => '6011',
        'Travel and TA' => '6019',
        'Hotel and Accommodation' => '6014',
        'Office Expense' => '6062',
        'Mobile and Telephone' => '6055',
        'Internet' => '6054',
        // ⚠ Cost of Sales, not overhead: freight to bring goods in belongs in
        // the cost of those goods.
        'Product Inward Freight' => '5300',
        'Product Sourcing' => '6174',
        'Packaging' => '6206',
        'Marketing' => '6289',
        'Customer Gift' => '6331',
        'Free Product' => '6332',
        'Courier and Delivery' => '6371',
        'Event and Guest Hospitality' => '6431',
        'Employee Welfare' => '6138',
        'Bank and MFS Charge' => '6471',
        'Licence and Testing' => '6505',
        'Repair and Maintenance' => '6556',
        'Other Approved Business Expense' => '6601',
    ];

    foreach ($expected as $name => $code) {
        $category = DB::table('expense_categories')->where('name', $name)->first();
        expect($category)->not->toBeNull("The chart lists the category {$name} and it was not planted");
        expect(Account::find($category->account_id)->code)
            ->toBe($code, "Category {$name} must post to {$code}");
    }

    // ⚠⚠ "Miscellaneous must NOT be the default category" is the document's own
    // sentence. Exactly one catch-all, and it is flagged so the approver looks
    // twice; a specific category must never be, or the warning stops meaning
    // anything.
    $flagged = DB::table('expense_categories')->where('is_active', true)->where('is_exception', true)->pluck('name');

    expect($flagged->all())->toBe(['Other Approved Business Expense']);

    // ⚠⚠ Section 18: raw material purchase is inventory and can never be an
    // expense, so the old Purchase of Materials category is retired rather than
    // remapped. It is deactivated, not deleted: a claim once pointed at it.
    $materials = DB::table('expense_categories')->where('name', 'Purchase of Materials')->first();

    expect($materials === null || (bool) $materials->is_active === false)->toBeTrue();
});
