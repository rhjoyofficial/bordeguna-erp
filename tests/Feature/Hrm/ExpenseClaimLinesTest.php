<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\ExpenseClaimWorkflowSeeder;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Notifications\ExpenseClaimReturnedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ONE CLAIM, MANY SPENDS, AND A WAY BACK (Joy, 9 September 2026).
 *
 * His three complaints, in his own words:
 *
 *   1. "The Hostinger bill has no category" - the list was short, unsearchable
 *      and could not be added to.
 *   2. One claim was one category, one amount, one receipt. A market trip is
 *      goods, a rickshaw, a courier and tea for the supplier, which meant four
 *      claims, four approvals, four payments and four vouchers for one
 *      afternoon.
 *   3. Approve or reject was not enough. A claim with one wrong figure had to be
 *      rejected and typed again from nothing.
 *
 * ⚠⚠ THE ONE-PRESS CASE IS PART OF THE CONTRACT. Fixing the trip by making
 * everybody start a claim, add a spend and then submit would have taxed the
 * common case to help the rare one. `action=submit` still does the whole thing
 * in a single request, and ExpenseClaimTest holds that path.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Trip Co', 'slug' => 'trip-co']);
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

    Carbon::setTestNow('2026-09-10 11:00:00');
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
        'employee_code' => 'BDG-000021',
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

    // Plants the accountant's categories, which only happens on a first visit.
    ($this->asStaff)()->get(route('hrm.my-expenses'));
});

afterEach(fn () => Carbon::setTestNow());

function tripCategory(string $name): int
{
    return (int) DB::table('expense_categories')->where('name', 'like', $name.'%')->value('id');
}

/**
 * One spend, posted the way the screen posts it.
 *
 * @param  array<string, mixed>  $overrides
 */
function addSpend(array $overrides = []): \Illuminate\Testing\TestResponse
{
    return (test()->asStaff)()->post(route('hrm.my-expenses.store'), array_merge([
        'action' => 'add',
        'spent_on' => '2026-09-08',
        'amount' => '250',
        'expense_category_id' => tripCategory('Courier'),
        'description' => 'Courier to Savar',
        'purpose' => 'Dhaka market trip',
        'receipt' => UploadedFile::fake()->image('courier.png', 600, 800),
    ], $overrides));
}

it('builds one claim out of an afternoon of spends and sends it once', function () {
    addSpend()->assertSessionHasNoErrors();

    $claim = ExpenseClaim::sole();

    // ⚠⚠ NOBODY HAS SEEN IT YET. A draft has no approval request at all, so it
    // cannot appear in an inbox, cannot be approved, and cannot be paid.
    expect($claim->isDraft())->toBeTrue()
        ->and($claim->approvalRequest)->toBeNull();

    // ⚠ The screen splits drafts from sent claims, and the NEWEST DRAFT IS
    // FIRST. The form relies on that ordering to point the next spend at the
    // claim just created; without it, pressing Add again would open a second
    // claim and split the trip after all.
    ($this->asStaff)()->get(route('hrm.my-expenses'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('drafts', fn ($drafts) => collect($drafts)->count() === 1
                && collect($drafts)->first()['id'] === $claim->id)
            ->where('claims', fn ($claims) => collect($claims)->isEmpty()));

    addSpend([
        'claim_id' => $claim->id,
        'amount' => '80',
        'expense_category_id' => tripCategory('Conveyance'),
        'description' => 'Rickshaw to the market',
        'receipt' => null,
    ])->assertSessionHasNoErrors();

    addSpend([
        'claim_id' => $claim->id,
        'spent_on' => '2026-09-07',
        'amount' => '120',
        'expense_category_id' => tripCategory('Courier'),
        'description' => 'Second courier',
    ])->assertSessionHasNoErrors();

    $claim->refresh()->load('lines');

    expect(ExpenseClaim::count())->toBe(1)
        ->and($claim->lines)->toHaveCount(3)
        // ⚠ The header is DERIVED: the total is the sum of the spends and the
        // date is the earliest of them, because a trip is dated from when it
        // started rather than from whichever line was typed last.
        ->and((float) $claim->amount)->toBe(450.0)
        ->and($claim->spent_on->toDateString())->toBe('2026-09-07');

    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->isDraft())->toBeFalse()
        ->and($claim->approvalRequest?->status)->toBe(ApprovalStatus::Pending);
});

it('posts one journal for the whole claim, one debit per account', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();

    addSpend(['claim_id' => $claim->id, 'amount' => '120', 'description' => 'Second courier']);
    addSpend([
        'claim_id' => $claim->id,
        'amount' => '80',
        'expense_category_id' => tripCategory('Conveyance'),
        'description' => 'Rickshaw',
        'receipt' => null,
    ]);

    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim))->assertSessionHasNoErrors();

    $journalId = $claim->fresh()->approval_journal_id;
    expect($journalId)->not->toBeNull();

    $courier = Account::query()->where('code', '6371')->value('id');
    $conveyance = Account::query()->where('code', '6011')->value('id');
    $payable = Account::bySlug('employee_reimbursements_payable')->id;

    $lineFor = fn (int $accountId) => (int) DB::table('journal_lines')
        ->where('journal_id', $journalId)->where('account_id', $accountId)->sum('amount_minor');

    // ⚠⚠ TWO COURIER SPENDS, ONE DEBIT. The ledger records what was spent on
    // what, not a transcript of the claim form, so lines sharing a head are
    // added together rather than posted twice.
    expect(DB::table('journal_lines')->where('journal_id', $journalId)->count())->toBe(3)
        ->and($lineFor($courier))->toBe(37000)
        ->and($lineFor($conveyance))->toBe(8000)
        // The credit is the sum of the debits, in paisa, so it cannot disagree.
        ->and($lineFor($payable))->toBe(-45000);
});

it('lets an approver refuse one spend and still pay the rest', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    addSpend(['claim_id' => $claim->id, 'amount' => '120', 'description' => 'Second courier']);
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $refused = $claim->fresh()->load('lines')->lines->last();

    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim), [
        'declines' => [['id' => $refused->id, 'reason' => 'The courier slip is for somebody else']],
    ])->assertSessionHasNoErrors();

    $claim->refresh()->load('lines');
    $refused->refresh();

    expect($refused->isDeclined())->toBeTrue()
        ->and($refused->declined_reason)->toContain('somebody else')
        // ⚠ The refused line is STILL ON THE CLAIM. The employee asked for that
        // money and is entitled to see what happened to the request.
        ->and($claim->lines)->toHaveCount(2)
        // ⚠⚠ ...and is not owed. The payable and the total are the approved
        // lines only, so nothing the approver refused reaches the ledger.
        ->and((float) $claim->amount)->toBe(250.0);

    $payable = Account::bySlug('employee_reimbursements_payable')->id;

    expect((int) DB::table('journal_lines')
        ->where('journal_id', $claim->approval_journal_id)
        ->where('account_id', $payable)->sum('amount_minor'))->toBe(-25000);
});

it('refuses to let declining every spend stand in for a rejection', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $line = $claim->fresh()->load('lines')->lines->first();

    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim), [
        'declines' => [['id' => $line->id, 'reason' => 'No']],
    ])->assertSessionHasErrors('declines');

    expect($claim->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($line->fresh()->isDeclined())->toBeFalse();
});

/**
 * ⚠⚠ THE THIRD OUTCOME, AND THE WHOLE POINT OF IT. Before this, a claim with one
 * wrong figure had to be REJECTED - which is final - and typed again from
 * nothing, losing the record of what was originally asked for.
 */
it('hands a claim back for correction and takes the same claim again', function () {
    Notification::fake();

    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $number = $claim->claim_number;

    ($this->asApprover)()->post(route('hrm.expense-claims.send-back', $claim), [
        'note' => 'The courier slip is unreadable. Photograph it again.',
    ])->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->isDraft())->toBeTrue()
        ->and($claim->wasSentBack())->toBeTrue()
        // Q3, and it is not a detail: the employee has already written this
        // number on something.
        ->and($claim->claim_number)->toBe($number)
        ->and($claim->approvalRequest->status)->toBe(ApprovalStatus::Returned)
        ->and($claim->approvalRequest->review_note)->toContain('unreadable')
        ->and($claim->state())->toBe('Sent back for correction');

    // ⚠⚠ WITHOUT THE BELL THE FEATURE DOES NOT EXIST: the claim would sit
    // silently in a drafts list the employee has no reason to look at.
    Notification::assertSentTo($this->staffUser, ExpenseClaimReturnedNotification::class);

    // ⚠ It has left every approver's queue. Read from ApprovalInbox rather than
    // the screen: the screen 403s an approver with nothing to do, so an empty
    // page and a refused page would be indistinguishable.
    expect(app(\App\Support\ApprovalInbox::class)->pendingActionableFor($this->approver))->toBeEmpty();

    // The employee fixes it and sends the SAME claim again.
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($claim->isDraft())->toBeFalse()
        // ⚠ ONE request row, reused. `approvalRequest` is a morphOne, so a
        // second row would be invisible to every reader in the system - and the
        // decision history stays in one readable place because of it.
        ->and($claim->approvalRequest->decisions()->count())->toBe(1)
        ->and($claim->approvalRequest->decisions()->first()->decision)->toBe(ApprovalStatus::Returned);
});

it('will not send a claim back without saying what to correct', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    ($this->asApprover)()->post(route('hrm.expense-claims.send-back', $claim), ['note' => ''])
        ->assertSessionHasErrors('note');

    expect($claim->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending);
});

it('keeps a draft out of the finance screen and the approvals inbox', function () {
    addSpend()->assertSessionHasNoErrors();

    ($this->asApprover)()->get(route('hrm.expense-claims.index'))
        ->assertInertia(fn (Assert $page) => $page->where('claims', fn ($claims) => collect($claims)->isEmpty()));

    // ⚠ Through the inbox itself, not its screen: the screen refuses an
    // approver with nothing to act on, so a 403 would look like a pass.
    expect(app(\App\Support\ApprovalInbox::class)->pendingActionableFor($this->approver))->toBeEmpty();
});

it('lets a spend be removed from a draft and refuses to touch a submitted one', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    addSpend(['claim_id' => $claim->id, 'amount' => '120', 'description' => 'Second courier']);

    $line = $claim->fresh()->load('lines')->lines->last();

    ($this->asStaff)()->delete(route('hrm.my-expenses.lines.destroy', [$claim, $line]))
        ->assertSessionHasNoErrors();

    expect($claim->fresh()->load('lines')->lines)->toHaveCount(1)
        ->and((float) $claim->fresh()->amount)->toBe(250.0);

    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();

    $remaining = $claim->fresh()->load('lines')->lines->first();

    // ⚠ A submitted claim is not the employee's any more. A stale tab must not
    // be able to quietly change what an approver is looking at.
    ($this->asStaff)()->delete(route('hrm.my-expenses.lines.destroy', [$claim, $remaining]))
        ->assertNotFound();
});

it('refuses to let one employee touch another employee draft', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();

    $intruderUser = User::factory()->create();
    $intruderUser->businesses()->attach($this->business->id);
    Employee::create([
        'business_id' => $this->business->id,
        'user_id' => $intruderUser->id,
        'employee_code' => 'BDG-000022',
        'first_name' => 'Nazmul', 'last_name' => 'Haque',
        'joining_date' => '2026-07-01', 'status' => 'active', 'employment_type' => 'permanent',
    ]);

    $this->actingAs($intruderUser)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.my-expenses.submit', $claim))
        ->assertNotFound();

    $this->actingAs($intruderUser)->withSession(['current_business_id' => $this->business->id])
        ->delete(route('hrm.my-expenses.destroy', $claim))
        ->assertNotFound();

    expect(ExpenseClaim::find($claim->id))->not->toBeNull();
});

/**
 * ⚠⚠ Q1, AND IT IS NOT A CONVENIENCE. A rickshaw hands you nothing to
 * photograph. Requiring proof for it does not produce proof; it pushes the spend
 * off the books entirely, which is worse than an unreceipted line an approver
 * can see and question.
 */
it('accepts a spend with no receipt only in the category the accountant flagged', function () {
    addSpend([
        'expense_category_id' => tripCategory('Conveyance'),
        'description' => 'Rickshaw',
        'receipt' => null,
    ])->assertSessionHasNoErrors();

    addSpend([
        'claim_id' => ExpenseClaim::sole()->id,
        'expense_category_id' => tripCategory('Courier'),
        'receipt' => null,
    ])->assertSessionHasErrors('receipt');

    expect(ExpenseClaim::sole()->load('lines')->lines)->toHaveCount(1);
});

it('will not send a claim with no spends on it', function () {
    $claim = ExpenseClaim::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'claim_number' => ExpenseClaim::nextNumber($this->business->id),
        'spent_on' => '2026-09-08',
        'amount' => '0',
        'purpose' => 'Nothing on it yet',
    ]);

    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasErrors('claim');

    expect($claim->fresh()->approvalRequest)->toBeNull();
});

/**
 * ⛔⛔ THE VOUCHER COULD NOT SAY WHICH TILL THE CASH LEFT (found on the rendered
 * page, 7 Sep 2026). A BOOKED claim settled in cash clears the payable directly
 * and writes NO expense voucher, so `paid_from` - which read the voucher's money
 * account - printed nothing, while the purchasing payment voucher beside it
 * named its account. `expense_claims` has no money-account column; the
 * settlement journal's credit line carries one, and that is where it reads now.
 */
it('prints every spend on the voucher and names the till the cash left', function () {
    addSpend()->assertSessionHasNoErrors();
    $claim = ExpenseClaim::sole();
    addSpend(['claim_id' => $claim->id, 'amount' => '120', 'description' => 'Second courier']);
    ($this->asStaff)()->post(route('hrm.my-expenses.submit', $claim))->assertSessionHasNoErrors();
    ($this->asApprover)()->post(route('hrm.expense-claims.approve', $claim))->assertSessionHasNoErrors();

    ($this->asApprover)()->post(route('hrm.expense-claims.settle', $claim), [
        'method' => 'payment',
        'money_account_id' => $this->till->id,
    ])->assertSessionHasNoErrors();

    expect($claim->fresh()->voucher)->toBeNull();

    ($this->asApprover)()->get(route('hrm.expense-claims.print', $claim))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/expense-claims/print')
            ->where('claim.paid_from', 'Till')
            ->where('claim.lines', fn ($lines) => collect($lines)->count() === 2
                && collect($lines)->sum(fn ($line) => (float) $line['amount']) === 370.0)
            // The PDF button builds its URL from this; it was missing, so the
            // download asked for `.../undefined/pdf`.
            ->where('claim.id', $claim->id));
});

/**
 * ⚠⚠ "THE HOSTINGER BILL HAS NO CATEGORY." The account existed all along - the
 * accountant's chart holds 6057 ERP and Hosting - and the screen could only
 * remap, never create, so the only place to file a real recurring spend was the
 * catch-all his own document forbids as a default.
 */
it('ships the hosting categories and lets the accountant add another', function () {
    ($this->asStaff)()->get(route('hrm.my-expenses'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => collect($categories)
            ->contains(fn ($category) => $category['name'] === 'ERP and Hosting')));

    ($this->asApprover)()->post(route('hrm.expense-categories.store'), [
        'name' => 'Legal and Notary',
        'name_bn' => 'আইন/নোটারি',
        'account_id' => Account::query()->where('code', '6371')->value('id'),
        'receipt_required' => true,
    ])->assertSessionHasNoErrors();

    expect(DB::table('expense_categories')->where('name', 'Legal and Notary')->exists())->toBeTrue();

    // ⛔ And an employee cannot: a category IS an accounting decision, and the
    // person spending the money is the one least able to get it right.
    ($this->asStaff)()->post(route('hrm.expense-categories.store'), [
        'name' => 'Whatever I Like',
        'account_id' => Account::query()->where('code', '6371')->value('id'),
    ])->assertForbidden();
});
