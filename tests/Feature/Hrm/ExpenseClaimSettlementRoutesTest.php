<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;

/**
 * Joy, 7 Sep 2026: every claim must be payable either instantly or with the
 * month's salary.
 *
 * ⛔⛔ THE DANGER THIS GUARDS. An expense claim is expensed the moment it is
 * APPROVED (Dr the category's account, Cr Employee Reimbursements Payable).
 * Payroll posts a `reimbursement` earning against an EXPENSE account, so simply
 * allowing the salary route would have recorded the same cost twice and nobody
 * would have seen it until a margin looked wrong.
 *
 * The fix is a separate adjustment type whose earning code maps to the PAYABLE.
 * These tests pin that, because the difference between the two mappings is
 * invisible on every screen and only shows up in the ledger.
 */
it('gives a claim settlement its own adjustment type, separate from a hand-typed reimbursement', function () {
    // ⚠ Kept separate deliberately. Remapping `reimbursement` itself would have
    // broken every hand-typed one by debiting a payable nothing had credited.
    expect(PayrollAdjustmentType::ExpenseClaimSettlement->value)->toBe('expense_claim_settlement')
        ->and(PayrollAdjustmentType::ExpenseClaimSettlement)->not->toBe(PayrollAdjustmentType::Reimbursement);
});

it('treats a claim settlement as an earning, so it adds to what the employee is paid', function () {
    expect(PayrollAdjustmentType::ExpenseClaimSettlement->isEarning())->toBeTrue();
});

it('⛔ keeps the settlement type out of the manual adjustment list', function () {
    // Being system generated is what keeps it out of the dropdown, which is what
    // stops somebody hand-typing a settlement that would debit a payable nothing
    // had credited.
    expect(PayrollAdjustmentType::ExpenseClaimSettlement->isSystemGenerated())->toBeTrue();

    $offered = collect(PayrollAdjustmentType::cases())
        ->reject(fn (PayrollAdjustmentType $t) => $t->isSystemGenerated())
        ->map(fn (PayrollAdjustmentType $t) => $t->value);

    expect($offered)->not->toContain('expense_claim_settlement')
        // The hand-typed one is still offered, and still means what it meant.
        ->and($offered)->toContain('reimbursement');
});

it('⛔⛔ maps a claim settlement to the payable and a plain reimbursement to an expense', function () {
    $business = Business::create(['name' => 'Mapping Co', 'slug' => 'mapping-co']);
    app(BusinessContext::class)->set($business->id);

    $this->seed(\App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder::class);
    $this->seed(\App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder::class);

    $on = now()->startOfDay();

    $settlement = AccountMapping::forKeyOn('payroll.earning.expense_claim_settlement', $on);
    $reimbursement = AccountMapping::forKeyOn('payroll.earning.reimbursement', $on);

    expect($settlement)->not->toBeNull('the settlement earning must be mapped, or payroll falls back to the expense default and double counts');

    $settlementAccount = Account::find($settlement->account_id);
    $reimbursementAccount = Account::find($reimbursement?->account_id);

    // The whole point: one clears a liability, the other records a cost.
    expect($settlementAccount->system_slug)->toBe('employee_reimbursements_payable')
        ->and($reimbursementAccount?->system_slug)->not->toBe('employee_reimbursements_payable');
});
