<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\RecordInterBusinessTransfer;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026): the Phase 0 world plus the
 * accounts documents (a payment voucher on an expense head, a money transfer),
 * and one way to preview and submit any registered type.
 */
final class AccountsWorld
{
    public CorrectionWorld $w;

    public Account $expense;

    public static function make(string $now = '2026-10-17 10:00:00'): self
    {
        return self::on(CorrectionWorld::make($now));
    }

    /** On a Phase 0 world already made (the policy kit). */
    public static function on(CorrectionWorld $w): self
    {
        $world = new self;
        $world->w = $w;
        $world->expense = Account::where('code', '6051')->sole();

        return $world;
    }

    /** Money to LaLaDia (the second business), booked in both; returns the transfer. */
    public function interBusinessTransfer(string $amount = '7000'): InterBusinessTransfer
    {
        $w = $this->w;

        if (! $w->owner->businesses()->whereKey($w->other->id)->exists()) {
            $w->owner->businesses()->attach($w->other->id);
            TenantScope::enter($w->other->id);
            $w->owner->assignRole(Role::where('name', 'Owner')->where('business_id', $w->other->id)->firstOrFail());
            MoneyAccount::create(['business_id' => $w->other->id, 'name' => 'LaLaDia Bank', 'type' => 'bank', 'is_active' => true, 'account_id' => Account::bySlug('cash_at_bank')->id]);
            TenantScope::enter($w->business->id);
            $w->owner->unsetRelation('roles');
        }

        $theirs = MoneyAccount::withoutGlobalScopes()->where('business_id', $w->other->id)->where('name', 'LaLaDia Bank')->firstOrFail();

        return app(RecordInterBusinessTransfer::class)->handle($w->bank, $theirs, $amount, '2026-10-15', 'Loan to LaLaDia', $w->owner);
    }

    /** A customer invoice of Tk 10,000 with Tk 4,000 written off; returns [invoice, adjustment]. */
    public function writeOff(): array
    {
        $w = $this->w;
        (new SalesAccountMappingSeeder)->run();
        $customer = Customer::query()->firstOrCreate(['business_id' => $w->business->id, 'code' => 'RAHMAN'], ['name' => 'Rahman Traders']);
        $number = 'WO-'.(SalesInvoice::query()->count() + 1);
        $invoice = SalesInvoice::create([
            'business_id' => $w->business->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
            'invoice_date' => '2026-10-01', 'subtotal' => '10000', 'total' => '10000', 'created_by' => $w->owner->id,
        ]);
        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-10-01'), [
            JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka('10000'), null, $customer),
            JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('10000')),
        ], source: $invoice), $w->owner);

        $adjustment = app(RecordPartyAdjustment::class)->handle('customer_write_off', '2026-10-12', $customer, null, [$invoice->id => '4000'], [], 'Shop closed', $w->owner);

        return [$invoice, $adjustment];
    }

    /** A posted payment voucher: Dr the expense head, Cr BRAC Bank. */
    public function payment(string $amount = '5000', string $date = '2026-10-15', ?User $by = null, array $extra = []): Voucher
    {
        $by ??= $this->w->financeA;
        $draft = app(SaveVoucherDraft::class)->handle(VoucherType::Payment, $extra + [
            'voucher_date' => $date,
            'money_account_id' => $this->w->bank->id,
            'counterparty' => 'Razzak Traders',
            'narration' => 'Office rent',
            'lines' => [['account_id' => $this->expense->id, 'amount' => $amount, 'narration' => 'Rent for October']],
        ], $by);

        return app(PostVoucher::class)->handle($draft, $by);
    }

    /** The voucher as its form would send it, with changes. */
    public function voucherForm(Voucher $voucher, array $changes = []): array
    {
        $voucher->loadMissing('lines');

        $form = [
            'voucher_date' => $voucher->voucher_date->toDateString(),
            'money_account_id' => $voucher->money_account_id,
            'counterparty' => $voucher->counterparty,
            'narration' => $voucher->narration,
            'lines' => $voucher->lines->map(fn ($l) => ['account_id' => $l->account_id, 'side' => $l->side, 'amount' => $l->amount()->toTaka(), 'narration' => $l->narration])->values()->all(),
        ];

        foreach ($changes as $key => $value) {
            if ($key === 'amount') {
                $form['lines'][0]['amount'] = $value;
            } else {
                $form[$key] = $value;
            }
        }

        return $form;
    }

    public function transfer(string $amount = '3000', string $date = '2026-10-15', string $fee = '0'): MoneyTransfer
    {
        return app(RecordMoneyTransfer::class)->handle(
            from: $this->w->bank,
            to: $this->w->cash,
            amount: $amount,
            transferredOn: $date,
            createdBy: $this->w->owner,
            fee: $fee,
            feeAccount: $fee === '0' ? null : $this->expense,
        );
    }

    public function input(string $type, Model $document, DocumentAction $action, ?array $replacement = null, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate, string $reason = 'Entered with the wrong amount'): CorrectionInput
    {
        return new CorrectionInput($type, (int) $document->getKey(), $action, new ActionReason(null, $reason), $replacement, $choice, (string) Str::uuid());
    }

    public function preview(string $type, Model $document, DocumentAction $action, User $by, ?array $replacement = null, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate): CorrectionPreview
    {
        return app(CorrectionService::class)->preview($this->input($type, $document, $action, $replacement, $choice), $by);
    }

    public function submit(string $type, Model $document, DocumentAction $action, User $by, ?array $replacement = null, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate, ?string $ack = null): CorrectionResult
    {
        $input = $this->input($type, $document, $action, $replacement, $choice);
        $preview = app(CorrectionService::class)->preview($input, $by);

        return app(CorrectionService::class)->submit($input, $by, $preview->previewToken, $ack);
    }

    /** Claims one line of the journal on a bank statement line; returns its id. */
    public function matchJournalLine(int $journalId): int
    {
        $line = DB::table('journal_lines')->where('journal_id', $journalId)->whereNotNull('money_account_id')->first();

        $import = DB::table('bank_statement_imports')->insertGetId([
            'business_id' => $this->w->business->id, 'money_account_id' => $line->money_account_id, 'filename' => 'brac.csv',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('bank_statement_lines')->insertGetId([
            'business_id' => $this->w->business->id, 'import_id' => $import, 'money_account_id' => $line->money_account_id,
            'txn_date' => '2026-10-15', 'amount' => $line->amount_minor / 100, 'match_status' => 'matched',
            'matched_journal_line_id' => $line->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Debits = credits over every journal of the business. */
    public function ledgerBalances(): bool
    {
        return (int) DB::table('journal_lines')->where('business_id', $this->w->business->id)->sum('amount_minor') === 0;
    }
}
