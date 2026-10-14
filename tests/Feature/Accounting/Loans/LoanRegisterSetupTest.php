<?php

use App\Modules\Accounting\database\seeders\LoanWorkflowSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\LoanTdsRule;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\CalendarEvent;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\CalendarFeed;
use App\Support\Attention\NeedsAttention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoanWorld;

/**
 * What the Loan Register needs around it (loans plan v2, phase 2): its slugs,
 * permissions, approval workflows and the TDS rule seeded OFF, each planted
 * idempotently; and its dates in Needs attention and on the calendar.
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

function loanMigration(string $name): object
{
    return require base_path("app/Modules/Accounting/database/migrations/{$name}.php");
}

it('finds Interest Income and Advance Income Tax by slug, and the migration that slugs them runs twice safely', function () {
    expect(Account::bySlug('interest_income')->code)->toBe('4920')
        ->and(Account::bySlug('advance_income_tax')->code)->toBe('1190');

    // Production's shape: both heads exist without a slug.
    DB::table('accounts')->whereIn('code', ['4920', '1190'])->update(['system_slug' => null]);
    $migration = loanMigration('2026_10_14_991200_interest_income_and_advance_tax_have_slugs');
    $migration->up();
    $migration->up();

    expect(Account::bySlug('interest_income')->code)->toBe('4920')
        ->and(Account::bySlug('advance_income_tax')->code)->toBe('1190');

    // A slug held by another head stops it with a sentence rather than guessing.
    DB::table('accounts')->where('code', '4920')->update(['system_slug' => null]);
    DB::table('accounts')->where('code', '4930')->update(['system_slug' => 'interest_income']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'already held by account 4930');
});

it('grants the register to Owner, Finance and Manager to read and to Owner and Finance to keep', function () {
    $staff = $this->w->member('Staff');

    expect($this->w->owner->can('accounting.loans.manage'))->toBeTrue()
        ->and($this->w->finance->can('accounting.loans.manage'))->toBeTrue()
        ->and($this->w->manager->can('accounting.loans.view'))->toBeTrue()
        ->and($this->w->manager->can('accounting.loans.manage'))->toBeFalse()
        ->and($staff->can('accounting.loans.view'))->toBeFalse();
});

it('plants the three Owner workflows and the TDS rule switched off, idempotently, by migration and by seeder', function () {
    $migration = loanMigration('2026_10_14_991300_loan_register_permissions_workflows_and_tds_rule');
    $migration->up();
    $migration->up();
    (new LoanWorkflowSeeder)->run();

    foreach (['loan_contract', 'loan_amendment', 'loan_write_off'] as $key) {
        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->where('business_id', $this->w->business->id)->where('key', $key)->sole();

        expect($workflow->is_active)->toBeTrue()
            ->and($workflow->stages()->sole()->allowed_roles)->toBe(['Owner']);
    }

    $rule = LoanTdsRule::query()->sole();

    expect($rule->is_enabled)->toBeFalse()
        ->and($rule->legal_basis)->toBe('ITA 2023 s.104 10%, to be confirmed by the accountant')
        ->and($rule->payee_kinds)->not->toContain('bank', 'nbfi')
        ->and((string) $rule->rate)->toBe('10.0000')
        ->and(LoanTdsRule::withoutGlobalScope(BusinessScope::class)->count())->toBe(2);
});

it('switches TDS on only with the accountant\'s confirmation, by the Owner', function () {
    $rule = LoanTdsRule::query()->sole();
    $payload = ['name' => $rule->name, 'payee_kinds' => ['individual', 'company'], 'rate' => '10', 'legal_basis' => 'ITA 2023 s.104', 'effective_from' => '2023-07-01', 'is_enabled' => true];

    $this->actingAs($this->w->finance)->put(route('accounting.loans.tds-rules.update', $rule), $payload)->assertForbidden();
    $this->actingAs($this->w->owner)->put(route('accounting.loans.tds-rules.update', $rule), $payload)->assertSessionHasErrors('confirmed_by');
    $this->actingAs($this->w->owner)->put(route('accounting.loans.tds-rules.update', $rule), $payload + ['confirmed_by' => 'K. Rahman, accountant', 'confirmed_on' => '2026-10-14'])->assertSessionHasNoErrors();

    expect($rule->fresh()->is_enabled)->toBeTrue()->and($rule->fresh()->confirmed_by)->toBe('K. Rahman, accountant');
});

it('puts installments due, installments overdue and maturities in Needs attention for whoever reads the register', function () {
    // Overdue: the default loan has missed every installment since 1 Feb.
    $late = $this->w->approved();
    $this->w->advance($late, '120000', '2026-01-01');
    // Due in two days: first installment of a loan from 16 Sep.
    $soon = $this->w->approved(['counterparty_id' => $this->w->padma->id, 'start_date' => '2026-09-16', 'maturity_date' => '2027-09-16']);
    $this->w->advance($soon, '10000', '2026-09-16');
    // Matures in 18 days.
    $maturing = $this->w->approved(['counterparty_id' => $this->w->tycoon->id, 'start_date' => '2026-05-01', 'maturity_date' => '2026-11-01', 'repayment_type' => 'bullet', 'frequency' => 'at_maturity']);
    $this->w->advance($maturing, '10000', '2026-05-01');

    $items = collect(app(NeedsAttention::class)->for($this->w->manager, $this->w->business->id)['items'])->where('source', 'loans_due')->keyBy('reasonKey');

    expect($items->keys()->sort()->values()->all())->toBe([
        'attention.reason.loan_installment_due',
        'attention.reason.loan_installment_overdue',
        'attention.reason.loan_maturity_soon',
    ])
        ->and($items['attention.reason.loan_installment_overdue']->severity)->toBe('high')
        ->and($items['attention.reason.loan_installment_overdue']->params['loans'])->toContain('LN-2026-0001')
        ->and($items['attention.reason.loan_installment_due']->params['date'])->toBe('16 Oct 2026')
        ->and($items['attention.reason.loan_maturity_soon']->params['date'])->toBe('1 Nov 2026');

    $staff = $this->w->member('Staff');
    expect(collect(app(NeedsAttention::class)->sourcesFor($staff))->map->key()->all())->not->toContain('loans_due');

    // Paying the late installments takes the overdue line off.
    $this->w->repay($late, ['date' => '2026-10-14', 'principal' => '120000', 'interest' => '0']);
    $after = collect(app(NeedsAttention::class)->for($this->w->manager, $this->w->business->id)['items'])->where('source', 'loans_due')->pluck('reasonKey')->all();
    expect($after)->not->toContain('attention.reason.loan_installment_overdue');
});

it('shows each unpaid installment and each maturity on the calendar, only to register readers', function () {
    $loan = $this->w->approved(['start_date' => '2026-09-16', 'maturity_date' => '2026-12-16']);
    $this->w->advance($loan, '30000', '2026-09-16');
    $staff = $this->w->member('Staff');

    $items = collect(app(CalendarFeed::class)->between($this->w->finance, '2026-10-01', '2026-12-31'));
    $loanItems = $items->filter(fn ($item) => str_starts_with($item['type'], 'loan_'));

    expect($loanItems->where('type', 'loan_due_in')->pluck('date')->all())->toBe(['2026-10-16', '2026-11-16', '2026-12-16'])
        ->and($loanItems->firstWhere('type', 'loan_maturity')['date'])->toBe('2026-12-16')
        ->and($loanItems->first()['url'])->toBe("/accounting/loans/{$loan->id}")
        ->and(collect(app(CalendarFeed::class)->between($staff, '2026-10-01', '2026-12-31'))->filter(fn ($i) => str_starts_with($i['type'], 'loan_')))->toBeEmpty();

    expect(CalendarEvent::query()->count())->toBe(0);
});
