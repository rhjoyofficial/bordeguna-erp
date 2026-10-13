<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\RecordOpeningBalanceDocument;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Actions\PostSalesJournals;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Opening receivables and payables (finance decision A4/A5, 29 Sep 2026): one
 * document per open invoice or bill, on its original date, against 3900, never
 * counted as a sale or a purchase.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-29 10:00:00');

    $this->business = Business::create(['name' => 'Opening Co', 'slug' => 'opening-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders', 'code' => 'RAHMAN']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Oil']);
    $this->record = app(RecordOpeningBalanceDocument::class);

    $this->balance = fn (string $slug, $party = null) => (int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug($slug)->id)
        ->when($party, fn ($q) => $q->where('party_type', $party->getMorphClass())->where('party_id', $party->id))
        ->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('opens a receivable on its original date, against 3900, as an opening-balance journal', function () {
    $invoice = $this->record->invoice($this->customer, '1045', '2026-07-10', '2026-08-09', '18000', $this->user);

    $journal = Journal::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->sole();

    expect($invoice->invoice_number)->toBe('OB-1045')
        ->and($invoice->is_opening)->toBeTrue()
        ->and($invoice->outstanding()->format())->toBe('18,000.00')
        ->and($journal->journal_type)->toBe(JournalType::OpeningBalance)
        ->and($journal->journal_date->toDateString())->toBe('2026-07-10')
        ->and(($this->balance)('accounts_receivable', $this->customer))->toBe(1_800_000)
        ->and(($this->balance)('opening_balance_equity'))->toBe(-1_800_000)
        ->and(CustomerStatement::for($this->customer, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay())->isConsistent())->toBeTrue();
});

it('opens a payable the same way, mirrored', function () {
    $bill = $this->record->bill($this->supplier, 'PO-77', '2026-08-15', null, '25000', $this->user);

    expect($bill->is_opening)->toBeTrue()
        ->and(($this->balance)('accounts_payable', $this->supplier))->toBe(-2_500_000)
        ->and(($this->balance)('opening_balance_equity'))->toBe(2_500_000)
        ->and(SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay())->isConsistent())->toBeTrue();
});

it('is never laid out as a sale or a purchase, and never counted as one', function () {
    $invoice = $this->record->invoice($this->customer, '1', '2026-07-10', null, '5000', $this->user);
    $bill = $this->record->bill($this->supplier, 'B-1', '2026-07-10', null, '5000', $this->user);

    expect(app(PostSalesJournals::class)->forInvoice($invoice, $this->user))->toBeNull()
        ->and(app(PostPurchaseJournals::class)->forBill($bill, $this->user))->toBeNull()
        ->and(Journal::count())->toBe(2)
        ->and(SalesInvoice::query()->trading()->count())->toBe(0)
        ->and(SalesInvoice::query()->count())->toBe(1);
});

it('is settled first by a receive voucher naming the customer, being the oldest', function () {
    config(['accounting.money_availability' => true]);
    $bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $opening = $this->record->invoice($this->customer, '1045', '2026-07-10', null, '18000', $this->user);

    app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Receipt, [
        'voucher_date' => '2026-09-29', 'money_account_id' => $bank->id,
        'lines' => [['account_id' => $this->customer->fresh()->account_id, 'amount' => '10000']],
    ], $this->user), $this->user);

    expect($opening->fresh()->outstanding()->format())->toBe('8,000.00');

    // Paid into, so it can no longer be removed.
    expect(fn () => $this->record->remove($opening->fresh(), $this->user))->toThrow(ValidationException::class, 'Reverse that first');
});

it('removes an opening balance entered by mistake, reversing its journal', function () {
    $invoice = $this->record->invoice($this->customer, '9', '2026-07-10', null, '4000', $this->user);

    $this->record->remove($invoice, $this->user);

    expect(SalesInvoice::count())->toBe(0)
        ->and(($this->balance)('accounts_receivable', $this->customer))->toBe(0)
        ->and(($this->balance)('opening_balance_equity'))->toBe(0);
});

// ⚠ Audit 13 Oct: removing the same opening balance twice (two tabs) is
// refused in words, and the journal is reversed once.
it('refuses to remove an opening balance that is already removed', function () {
    $invoice = $this->record->invoice($this->customer, '10', '2026-07-10', null, '4000', $this->user);
    $staleCopy = SalesInvoice::findOrFail($invoice->id);

    $this->record->remove($invoice, $this->user);

    expect(fn () => $this->record->remove($staleCopy, $this->user))
        ->toThrow(ValidationException::class, 'already been removed');

    expect(($this->balance)('opening_balance_equity'))->toBe(0);
});

// ⚠ Audit 13 Oct: a write-off against an opening invoice left it "removable",
// and the delete then hit the allocation's foreign key as a raw 500.
it('refuses to remove an opening balance with an adjustment against it', function () {
    $invoice = $this->record->invoice($this->customer, '11', '2026-07-10', null, '4000', $this->user);

    app(RecordPartyAdjustment::class)->handle('customer_write_off', '2026-09-29', $this->customer, null, [$invoice->id => '500'], [], 'Bad debt', $this->user);

    expect(fn () => $this->record->remove($invoice->fresh(), $this->user))
        ->toThrow(ValidationException::class, 'Remove that adjustment first');

    expect(SalesInvoice::whereKey($invoice->id)->exists())->toBeTrue();
});

it('refuses a duplicate number, a zero amount, a future date and a due date before the date', function () {
    $this->record->invoice($this->customer, '1045', '2026-07-10', null, '100', $this->user);

    expect(fn () => $this->record->invoice($this->customer, '1045', '2026-07-10', null, '100', $this->user))->toThrow(ValidationException::class, 'already entered')
        ->and(fn () => $this->record->invoice($this->customer, '2', '2026-07-10', null, '0', $this->user))->toThrow(ValidationException::class)
        ->and(fn () => $this->record->invoice($this->customer, '3', '2026-10-10', null, '100', $this->user))->toThrow(ValidationException::class)
        ->and(fn () => $this->record->bill($this->supplier, 'X', '2026-07-10', '2026-07-01', '100', $this->user))->toThrow(ValidationException::class);
});

it('is entered from the screen by whoever may post journals, and nobody else', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $this->user->businesses()->attach($this->business->id);
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'accounting.journal.post', 'guard_name' => 'web']));

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->post(route('accounting.opening-balances.store'), [
            'kind' => 'payable', 'party_id' => $this->supplier->id, 'reference' => 'SB-9',
            'date' => '2026-08-01', 'due_date' => '', 'amount' => '7500',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->get(route('accounting.opening-balances.index'))->assertOk();

    expect(SupplierBill::query()->where('is_opening', true)->sole()->bill_number)->toBe('SB-9');

    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);

    $this->actingAs($clerk)->withSession(['current_business_id' => $this->business->id])
        ->get(route('accounting.opening-balances.index'))->assertForbidden();
});
