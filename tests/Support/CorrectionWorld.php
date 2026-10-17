<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ActionReasons;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\database\seeders\CorrectionWorkflowSeeder;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One business with a chart, the people a correction involves, BRAC Bank and
 * Cash, the correction workflows, the reason lists and the Tk 20,000 limit:
 * the world every edit, delete and correct test starts from (Phase 0, 17 Oct 2026).
 *
 *   owner      Owner role
 *   financeA   Finance, records most vouchers (the "maker")
 *   financeB   Finance, asks for most corrections
 *   financeC   Finance, an independent second person
 *   manager    Manager: may not correct
 *   sales      Sales: may not even read the books
 */
final class CorrectionWorld
{
    public Business $business;

    public Business $other;

    public User $owner;

    public User $financeA;

    public User $financeB;

    public User $financeC;

    public User $manager;

    public User $sales;

    public MoneyAccount $bank;

    public MoneyAccount $cash;

    public Account $scrap;

    public Account $interest;

    public static function make(string $now = '2026-10-17 10:00:00'): self
    {
        Carbon::setTestNow($now);

        $world = new self;
        $world->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
        $world->other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);

        foreach ([$world->business, $world->other] as $business) {
            foreach (['Owner', 'Finance', 'Manager', 'Sales'] as $name) {
                Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);
            }
        }

        Permission::findOrCreate('recycle_bin.manage', 'web');
        (new AccountingPermissionsSeeder)->run();
        (new ChartOfAccountsSeeder)->run();
        (new CorrectionWorkflowSeeder)->run();
        ActionReasons::seedFor($world->business->id);
        ActionReasons::seedFor($world->other->id);

        foreach ([$world->business, $world->other] as $business) {
            Role::where('name', 'Owner')->where('business_id', $business->id)->first()->givePermissionTo('recycle_bin.manage');
        }

        $world->enter($world->business);

        FinancePolicy::create(['business_id' => $world->business->id, 'refuse_negative_money' => false, 'payment_second_approval_above_minor' => 2_000_000]);

        $world->owner = $world->member('Owner', 'Owner');
        $world->financeA = $world->member('Finance', 'Musa');
        $world->financeB = $world->member('Finance', 'Shuborna');
        $world->financeC = $world->member('Finance', 'Moin');
        $world->manager = $world->member('Manager', 'Manager');
        $world->sales = $world->member('Sales', 'Sales');

        $world->bank = MoneyAccount::create([
            'business_id' => $world->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
            'account_id' => Account::bySlug('cash_at_bank')->id,
        ]);

        $world->cash = MoneyAccount::create([
            'business_id' => $world->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true,
            'account_id' => Account::bySlug('cash_in_hand')->id,
        ]);

        $world->scrap = Account::where('code', '4910')->sole();
        $world->interest = Account::where('code', '4920')->sole();

        return $world;
    }

    public function enter(Business $business): void
    {
        TenantScope::enter($business->id);
    }

    public function member(string $role, string $name, ?Business $business = null): User
    {
        $business ??= $this->business;
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $business->id)->firstOrFail());

        return $user;
    }

    /** Records an income voucher through the real action. */
    public function record(string $amount = '5000', string $date = '2026-10-15', ?User $by = null, ?Account $head = null, ?MoneyAccount $into = null, string $from = 'Adabor scrap dealer'): IncomeVoucher
    {
        return app(RecordIncomeVoucher::class)->handle(
            moneyAccount: $into ?? $this->bank,
            receivedOn: $date,
            lines: [['account' => $head ?? $this->scrap, 'amount' => $amount, 'description' => 'Empty drums sold']],
            createdBy: $by ?? $this->financeA,
            receivedFrom: $from,
        );
    }

    /** The voucher as the Correct form would send it, with changes. */
    public function replacement(IncomeVoucher $voucher, array $changes = []): array
    {
        $voucher->loadMissing('lines');

        $form = [
            'money_account_id' => $voucher->money_account_id,
            'received_on' => $voucher->received_on->toDateString(),
            'customer_id' => $voucher->customer_id,
            'received_from' => $voucher->received_from,
            'reference' => $voucher->reference,
            'note' => $voucher->note,
            'lines' => $voucher->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'amount' => (string) (float) $l->amount,
                'description' => $l->description,
            ])->values()->all(),
        ];

        foreach ($changes as $key => $value) {
            if ($key === 'amount') {
                $form['lines'][0]['amount'] = $value;
            } elseif ($key === 'account_id') {
                $form['lines'][0]['account_id'] = $value;
            } else {
                $form[$key] = $value;
            }
        }

        return $form;
    }

    public function input(IncomeVoucher $voucher, DocumentAction $action, ?array $replacement = null, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate, string $reason = 'Wrong amount typed in', ?string $key = null): CorrectionInput
    {
        return new CorrectionInput(IncomeVoucherActionPolicy::TYPE, (int) $voucher->id, $action, new ActionReason(null, $reason), $replacement, $choice, $key ?? (string) Str::uuid());
    }

    public function preview(CorrectionInput $input, User $by): CorrectionPreview
    {
        return app(CorrectionService::class)->preview($input, $by);
    }

    /** Preview, then submit with the token, as the dialog does. */
    public function submit(CorrectionInput $input, User $by, ?string $ack = null): CorrectionResult
    {
        $preview = $this->preview($input, $by);

        return app(CorrectionService::class)->submit($input, $by, $preview->previewToken, $ack);
    }

    public function reverse(IncomeVoucher $voucher, User $by, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate): CorrectionResult
    {
        return $this->submit($this->input($voucher, DocumentAction::Reverse, null, $choice), $by);
    }

    public function correct(IncomeVoucher $voucher, User $by, array $changes): CorrectionResult
    {
        return $this->submit($this->input($voucher, DocumentAction::Correct, $this->replacement($voucher, $changes)), $by);
    }

    /** Claims the voucher's money line on a bank statement line; returns the statement line id. */
    public function matchOnBankStatement(IncomeVoucher $voucher): int
    {
        $moneyLine = DB::table('journal_lines')->where('journal_id', $voucher->fresh()->journal_id)->where('amount_minor', '>', 0)->value('id');

        $import = DB::table('bank_statement_imports')->insertGetId([
            'business_id' => $this->business->id, 'money_account_id' => $this->bank->id, 'filename' => 'brac.csv',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('bank_statement_lines')->insertGetId([
            'business_id' => $this->business->id, 'import_id' => $import, 'money_account_id' => $this->bank->id,
            'txn_date' => $voucher->received_on->toDateString(), 'amount' => (string) $voucher->total, 'match_status' => 'matched',
            'matched_journal_line_id' => $moneyLine, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function closeThrough(string $date): void
    {
        AccountingPeriod::closeThrough(Carbon::parse($date), $this->owner, 'Closed for the test');
    }

    /** The signed ledger balance on a head (debits positive), this business. */
    public function balance(Account|string $head, ?string $from = null, ?string $to = null): int
    {
        $id = $head instanceof Account ? $head->id : Account::where('code', $head)->sole()->id;

        return (int) DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.business_id', $this->business->id)
            ->where('journal_lines.account_id', $id)
            ->when($from, fn ($q) => $q->where('journals.journal_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journals.journal_date', '<=', $to))
            ->sum('journal_lines.amount_minor');
    }
}
