<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write-offs, discounts and set-offs against named invoices and bills (finance
 * decision C3, the journal half, 29 Sep 2026): the document drops, the ledger
 * moves, and the statement, ageing and reconciliation still agree.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-29 10:00:00');

    $this->business = Business::create(['name' => 'Adjust Co', 'slug' => 'adjust-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders', 'code' => 'RAHMAN']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders (supplier)']);
    $capital = Account::bySlug('owners_capital');

    $this->invoice = function (string $total, string $number) use ($capital) {
        $invoice = SalesInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'invoice_number' => $number,
            'invoice_date' => '2026-08-01', 'subtotal' => $total, 'total' => $total, 'created_by' => $this->user->id,
        ]);
        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-08-01'), [
            JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka($total), null, $this->customer),
            JournalLineDraft::credit($capital->id, Money::ofTaka($total)),
        ], source: $invoice), $this->user);

        return $invoice;
    };

    $this->bill = function (string $total, string $number) use ($capital) {
        $bill = SupplierBill::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'bill_number' => $number,
            'bill_date' => '2026-08-01', 'total' => $total, 'created_by' => $this->user->id,
        ]);
        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-08-01'), [
            JournalLineDraft::credit(Account::bySlug('accounts_payable')->id, Money::ofTaka($total), null, $this->supplier),
            JournalLineDraft::debit($capital->id, Money::ofTaka($total)),
        ], source: $bill), $this->user);

        return $bill;
    };

    $this->record = app(RecordPartyAdjustment::class);
    $this->balance = fn (string $slug) => (int) DB::table('journal_lines')->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
    $this->customerStatement = fn () => CustomerStatement::for($this->customer, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay());
    $this->supplierStatement = fn () => SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')->endOfDay());
});

afterEach(fn () => Carbon::setTestNow());

it('writes off part of an invoice as a bad debt: the invoice owes less and the books agree everywhere', function () {
    $invoice = ($this->invoice)('10000', 'INV-1');

    $this->record->handle('customer_write_off', '2026-09-29', $this->customer, null, [$invoice->id => '4000'], [], 'Shop closed', $this->user);

    expect($invoice->fresh()->outstanding()->format())->toBe('6,000.00')
        ->and(SalesInvoice::query()->unsettled()->count())->toBe(1)
        ->and(($this->balance)('bad_debts'))->toBe(400_000)
        ->and(($this->balance)('accounts_receivable'))->toBe(600_000)
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue();

    $problems = SubledgerReconciliation::reconcileAllBusinesses()['problems'];
    expect(collect($problems)->filter(fn ($p) => str_contains(json_encode($p), 'Receivable')))->toBeEmpty();
});

it('gives a discount received on a bill, and sets a customer off against the same party as supplier', function () {
    $bill = ($this->bill)('8000', 'SB-1');
    $invoice = ($this->invoice)('5000', 'INV-2');

    $this->record->handle('supplier_discount', '2026-09-29', null, $this->supplier, [], [$bill->id => '500'], 'Early payment', $this->user);
    expect($bill->fresh()->outstanding()->format())->toBe('7,500.00')
        ->and(($this->balance)('discounts_received'))->toBe(-50_000);

    $this->record->handle('set_off', '2026-09-29', $this->customer, $this->supplier, [$invoice->id => '5000'], [$bill->id => '5000'], 'Netted', $this->user);

    expect($invoice->fresh()->outstanding()->minor)->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('2,500.00')
        ->and(($this->customerStatement)()->isConsistent())->toBeTrue()
        ->and(($this->supplierStatement)()->isConsistent())->toBeTrue();
});

it('refuses more than a document still owes, a set-off that does not balance, and no reason', function () {
    $invoice = ($this->invoice)('1000', 'INV-3');
    $bill = ($this->bill)('1000', 'SB-3');

    expect(fn () => $this->record->handle('customer_discount', '2026-09-29', $this->customer, null, [$invoice->id => '1000.01'], [], 'x', $this->user))
        ->toThrow(ValidationException::class, 'only 1,000.00 outstanding')
        ->and(fn () => $this->record->handle('set_off', '2026-09-29', $this->customer, $this->supplier, [$invoice->id => '500'], [$bill->id => '400'], 'x', $this->user))
        ->toThrow(ValidationException::class, 'same amount on both sides')
        ->and(fn () => $this->record->handle('customer_discount', '2026-09-29', $this->customer, null, [$invoice->id => '10'], [], ' ', $this->user))
        ->toThrow(ValidationException::class);
});

it('shows in the ageing and comes back out when removed', function () {
    $invoice = ($this->invoice)('10000', 'INV-4');
    $adjustment = $this->record->handle('customer_discount', '2026-09-29', $this->customer, null, [$invoice->id => '1000'], [], 'Loyalty', $this->user);

    $aged = Ageing::receivables(Carbon::parse('2026-09-29'));
    expect($aged->total()->format())->toBe('9,000.00')
        ->and($aged->tiesOut())->toBeTrue();

    $this->record->remove($adjustment, $this->user);

    expect(PartyAdjustment::count())->toBe(0)
        ->and($invoice->fresh()->outstanding()->format())->toBe('10,000.00')
        ->and(($this->balance)('discounts_allowed'))->toBe(0);
});
