<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\Loans\LoanApprovals;
use App\Modules\Accounting\Actions\Loans\RecordLoanAdvance;
use App\Modules\Accounting\Actions\Loans\RecordLoanRepayment;
use App\Modules\Accounting\Actions\Loans\SaveLoanContract;
use App\Modules\Accounting\Actions\SaveCounterparty;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\LoanWorkflowSeeder;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One business with a chart, the Owner, Finance and Manager, a bank, three
 * counterparties and the loan workflows: the world every Loan Register test
 * starts from (loans plan v2, phase 2).
 */
final class LoanWorld
{
    public Business $business;

    public Business $laladia;

    public User $owner;

    public User $finance;

    public User $manager;

    public MoneyAccount $bank;

    /** A related party: the owner's company. */
    public Counterparty $tycoon;

    /** A finance company (NBFI): no TDS by the default rule. */
    public Counterparty $padma;

    /** A private lender: TDS applies when the rule is on. */
    public Counterparty $rahim;

    public static function make(string $now = '2026-10-14 10:00:00'): self
    {
        Carbon::setTestNow($now);

        $world = new self;
        $world->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7, 'address' => 'Dhaka']);
        $world->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
        $world->enter($world->business);

        foreach ([$world->business, $world->laladia] as $business) {
            foreach (['Owner', 'Finance', 'Manager', 'Staff'] as $name) {
                Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);
            }
        }

        (new AccountingPermissionsSeeder)->run();
        (new ChartOfAccountsSeeder)->run();
        (new LoanWorkflowSeeder)->run();
        $world->enter($world->business);

        FinancePolicy::create(['business_id' => $world->business->id, 'refuse_negative_money' => false, 'payment_second_approval_above_minor' => 0]);

        $world->owner = $world->member('Owner');
        $world->finance = $world->member('Finance');
        $world->manager = $world->member('Manager');

        $world->bank = MoneyAccount::create([
            'business_id' => $world->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
            'account_id' => Account::bySlug('cash_at_bank')->id,
        ]);

        $save = fn (array $data) => app(SaveCounterparty::class)->handle($data, $world->owner);
        $world->tycoon = $save(['legal_name' => 'Tycoon - Hi-Tech Park', 'kind' => 'company', 'related_party' => true, 'relationship' => "The owner's company"]);
        $world->padma = $save(['legal_name' => 'Padma Finance PLC', 'kind' => 'nbfi']);
        $world->rahim = $save(['legal_name' => 'Abdur Rahim', 'kind' => 'individual', 'phone' => '01711000111']);

        return $world;
    }

    public function enter(Business $business): void
    {
        TenantScope::enter($business->id);
        app(BusinessContext::class)->set($business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
    }

    public function member(string $role, ?Business $business = null): User
    {
        $business ??= $this->business;
        $user = User::factory()->create(['name' => $role]);
        $user->businesses()->attach($business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $business->id)->firstOrFail());

        return $user;
    }

    /**
     * A draft loan. Default: 120,000 lent to Abdur Rahim at 12% for a year,
     * repaid monthly in equal installments from 1 Jan 2026.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function draft(array $overrides = [], ?User $by = null): LoanContract
    {
        return app(SaveLoanContract::class)->handle($overrides + [
            'direction' => 'given',
            'counterparty_id' => $this->rahim->id,
            'principal' => '120000',
            'start_date' => '2026-01-01',
            'maturity_date' => '2027-01-01',
            'annual_rate' => '12',
            'repayment_type' => 'equal_installments',
            'frequency' => 'monthly',
            'grace_months' => 0,
            'waterfall' => 'interest_first',
        ], $by ?? $this->finance);
    }

    /** @param  array<string, mixed>  $overrides */
    public function approved(array $overrides = []): LoanContract
    {
        $loan = $this->draft($overrides);
        $approvals = app(LoanApprovals::class);
        $approvals->submit($loan, $this->finance);
        $approvals->approve($loan->fresh(), $this->owner);

        return $loan->fresh();
    }

    public function advance(LoanContract $loan, string $amount, string $date, ?string $key = null): LoanEntry
    {
        return app(RecordLoanAdvance::class)->handle($loan->fresh(), [
            'date' => $date, 'money_account_id' => $this->bank->id, 'amount' => $amount, 'submission_key' => $key,
        ], $this->finance);
    }

    /** @param  array<string, mixed>  $data */
    public function repay(LoanContract $loan, array $data): LoanEntry
    {
        return app(RecordLoanRepayment::class)->handle($loan->fresh(), $data + ['money_account_id' => $this->bank->id], $this->finance);
    }

    /** The signed ledger balance (debits positive) on a head, optionally for one counterparty, in this business. */
    public function ledger(string $slug, ?Counterparty $party = null): int
    {
        return (int) JournalLine::query()
            ->where('account_id', Account::bySlug($slug)->id)
            ->when($party !== null, fn ($q) => $q->where('party_type', (new Counterparty)->getMorphClass())->where('party_id', $party->id))
            ->sum('amount_minor');
    }

    /** The sentence a refusal carries, or '' when nothing was refused. */
    public static function refusal(callable $act): string
    {
        try {
            $act();
        } catch (JournalException $e) {
            return $e->getMessage();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return '';
    }
}
