<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ The screens of party ledgers (20 Oct 2026): the voucher form's party
 * picker and bill-wise lookup, the customer ledger view, and Tally's Sundry
 * Creditors / Sundry Debtors groups drilling to each party's ledger. Every
 * page checks its own total against the control accounts.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->business = Business::create(['name' => 'Screens Co', 'slug' => 'screens-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    foreach (['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($role);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders', 'code' => 'RAHMAN'])->fresh();
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Oil'])->fresh();

    $capital = Account::bySlug('owners_capital');

    $invoice = SalesInvoice::create([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'INV-1',
        'invoice_date' => '2026-08-01', 'subtotal' => '4000', 'total' => '4000', 'created_by' => $this->user->id,
    ]);
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-08-01'), [
        JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka('4000'), null, $this->customer),
        JournalLineDraft::credit($capital->id, Money::ofTaka('4000')),
    ], source: $invoice), $this->user);

    $this->bill = SupplierBill::create([
        'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'bill_number' => 'SB-1',
        'bill_date' => '2026-08-05', 'total' => '8000', 'created_by' => $this->user->id,
    ]);
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-08-05'), [
        JournalLineDraft::credit(Account::bySlug('accounts_payable')->id, Money::ofTaka('8000'), null, $this->supplier),
        JournalLineDraft::debit($capital->id, Money::ofTaka('8000')),
    ], source: $this->bill), $this->user);
});

afterEach(fn () => Carbon::setTestNow());

it('gives the payment form each party with its own head, and the owner\'s TDS / VDS rates', function () {
    WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => null, 'name' => 'Supply', 'rate' => '5', 'effective_from' => '2026-07-01']);

    $this->actingAs($this->user)->get('/accounting/vouchers/payment/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/vouchers/form')
            ->where('suppliers.0.head', $this->supplier->account_id)
            ->where('customers.0.head', $this->customer->account_id)
            ->has('withholdingRates', 1)
            ->where('withholdingRates.0.kind', 'tds'));
});

it('answers the bill-wise lookup for a party line, per voucher type', function () {
    $this->actingAs($this->user)
        ->getJson(route('accounting.vouchers.party-items', ['type' => 'payment', 'head' => $this->supplier->account_id, 'date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonPath('mode', 'supplier_payment')
        ->assertJsonPath('rest_is_advance', true)
        ->assertJsonPath('items.0.ref', "bill:{$this->bill->id}")
        ->assertJsonPath('items.0.open', '8000.00');

    $this->actingAs($this->user)
        ->getJson(route('accounting.vouchers.party-items', ['type' => 'receipt', 'head' => $this->supplier->account_id, 'date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonPath('mode', 'supplier_refund')
        ->assertJsonPath('rest_is_advance', false)
        ->assertJsonCount(0, 'items');

    // A plain head is no party: nothing to share out.
    $this->actingAs($this->user)
        ->getJson(route('accounting.vouchers.party-items', ['type' => 'payment', 'head' => Account::bySlug('owners_capital')->id]))
        ->assertOk()
        ->assertJsonPath('mode', null);
});

it('saves the party picked as Paid to, with bill-wise details, through the form', function () {
    $this->actingAs($this->user)->post('/accounting/vouchers/payment', [
        'voucher_date' => '2026-09-20',
        'money_account_id' => $this->bank->id,
        'party' => "supplier:{$this->supplier->id}",
        'lines' => [[
            'account_id' => $this->supplier->account_id, 'amount' => '3000',
            'bill_wise' => [['ref' => "bill:{$this->bill->id}", 'amount' => '3000']],
        ]],
        'post' => true,
    ])->assertRedirect();

    expect($this->bill->fresh()->outstanding()->format())->toBe('5,000.00');
});

it('opens the customer ledger view from the customer head in the General Ledger and on its own page', function () {
    $this->actingAs($this->user)
        ->get('/accounting/general-ledger?account='.$this->customer->account_id.'&from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/general-ledger/index')
            ->where('customer_ledger.customer.name', 'Rahman Traders')
            ->where('customer_ledger.cards.receivable.amount', '4,000.00')
            ->where('customer_ledger.consistent', true));

    $this->actingAs($this->user)
        ->get('/accounting/customer-ledger?customer='.$this->customer->id.'&from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/customer-ledger/index')
            ->where('ledger.accounts.0.role', 'receivable')
            ->where('ledger.consistent', true));
});

it('lists every supplier under Sundry Creditors and every customer under Sundry Debtors, tied to the control accounts', function () {
    $this->actingAs($this->user)->get('/accounting/sundry-creditors?from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/party-groups/index')
            ->where('group', 'creditors')
            ->has('rows', 1)
            ->where('rows.0.name', 'Padma Oil')
            ->where('rows.0.closing', '8,000.00 Cr')
            ->where('rows.0.url', fn ($url) => str_contains((string) $url, 'account='.$this->supplier->account_id))
            ->where('consistent', true));

    $this->actingAs($this->user)->get('/accounting/sundry-debtors?from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/party-groups/index')
            ->where('rows.0.name', 'Rahman Traders')
            ->where('rows.0.closing', '4,000.00 Dr')
            ->where('total.closing', '4,000.00 Dr')
            ->where('consistent', true));
});

it('links the Accounts Payable ledger to Sundry Creditors', function () {
    $this->actingAs($this->user)
        ->get('/accounting/general-ledger?account='.Account::bySlug('accounts_payable')->id.'&from=2026-07-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('party_group', 'creditors'));
});

it('keeps the party pages behind the books permission', function () {
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);

    $this->actingAs($stranger)->get('/accounting/sundry-creditors')->assertForbidden();
    $this->actingAs($stranger)->get('/accounting/customer-ledger')->assertForbidden();
    $this->actingAs($stranger)->getJson(route('accounting.vouchers.party-items', ['type' => 'payment', 'head' => $this->supplier->account_id]))->assertForbidden();
});
