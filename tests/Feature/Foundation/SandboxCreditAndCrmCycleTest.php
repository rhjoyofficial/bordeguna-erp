<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\database\seeders\SandboxSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Selling ON CREDIT, collecting later, and capturing a lead - through HTTP.
 *
 * ⚠⚠ WHAT THIS IS HUNTING. The end-to-end cycle proved the CASH path: goods in,
 * goods out, money taken at the till. A credit sale is the other half of Bor De
 * Guna's trade and it exercises two things the cash path cannot, because in a
 * counter sale they are debited and credited within milliseconds and net to
 * zero: Accounts Receivable actually CARRYING a balance, and a receipt arriving
 * on a different day from the invoice.
 *
 * ⚠ Sandbox business only. Bor De Guna receives no dummy transaction.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    (new SandboxSeeder)->run();

    $this->business = Business::withoutGlobalScopes()->where('slug', SandboxSeeder::SLUG)->sole();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create(['name' => 'DUMMY Operator']);
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->warehouse = Warehouse::where('code', 'ADABOR')->sole();
    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    // Stock to sell: 100 jars at 480, billed but not yet paid for.
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();
    $supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'DUMMY-SUP-CR', 'name' => 'DUMMY Supplier']);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $honey, 'quantity' => '100', 'unit_cost' => '480.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->user);
    app(RecordSupplierBill::class)->handle(
        supplier: $supplier,
        billNumber: 'DUMMY-BILL-CR',
        billDate: Carbon::parse('2026-08-09'),
        lines: [['line' => $line, 'quantity' => '100', 'unitCost' => '480.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $honey->id,
        'unit_price' => '850.0000', 'effective_from' => '2026-08-01',
    ]);

    $this->honey = $honey;
    $this->till = MoneyAccount::where('name', 'DUMMY Cash in Hand')->sole();
});

afterEach(fn () => Carbon::setTestNow());

// ── Credit sale, then collection ────────────────────────────────────────────

it('sells on credit, leaves the money owed, and collects it later', function () {
    $shop = Customer::create([
        'business_id' => $this->business->id, 'code' => 'DUMMY-CUST-01', 'name' => 'DUMMY Adabor Grocery',
    ]);

    // ⚠⚠ NO money_account_id. That single omission is the whole seam between
    // retail and credit: the goods go out, the invoice stands open, and the
    // same documents describe both kinds of trade.
    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $shop->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '20']],
    ])->assertRedirect();

    $invoice = SalesInvoice::sole();

    // 20 at 850 = 17,000 owed. ⚠ In a CASH sale receivable is debited and
    // credited within milliseconds and nets to zero, so this is the first time
    // the account has ever carried a real balance.
    expect(($this->balanceOn)('accounts_receivable'))->toBe(1700000)
        ->and(($this->balanceOn)('sales_revenue'))->toBe(-1700000)
        ->and(($this->balanceOn)('cost_of_goods_sold'))->toBe(960000)
        ->and(($this->balanceOn)('cash_in_hand'))->toBe(0);

    // Part payment a week later.
    Carbon::setTestNow('2026-08-16 10:00:00');

    $this->actingAs($this->user)->post("/sales/invoices/{$invoice->id}/receipts", [
        'money_account_id' => $this->till->id,
        'amount' => '10000.00',
        'received_on' => '2026-08-16',
        'reference' => 'DUMMY-RCV-01',
    ])->assertRedirect();

    expect(($this->balanceOn)('cash_in_hand'))->toBe(1000000)
        // 17,000 owed less 10,000 collected.
        ->and(($this->balanceOn)('accounts_receivable'))->toBe(700000);

    // The rest.
    $this->actingAs($this->user)->post("/sales/invoices/{$invoice->id}/receipts", [
        'money_account_id' => $this->till->id,
        'amount' => '7000.00',
        'received_on' => '2026-08-16',
    ])->assertRedirect();

    expect(($this->balanceOn)('accounts_receivable'))->toBe(0)
        ->and(($this->balanceOn)('cash_in_hand'))->toBe(1700000);

    expect((int) JournalLine::sum('amount_minor'))->toBe(0);
    Journal::all()->each(fn (Journal $journal) => expect($journal->balance()->isZero())->toBeTrue());
});

it('refuses to collect more than the invoice is worth', function () {
    $shop = Customer::create([
        'business_id' => $this->business->id, 'code' => 'DUMMY-CUST-02', 'name' => 'DUMMY Savar Store',
    ]);

    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $shop->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '10']],
    ])->assertRedirect();

    $invoice = SalesInvoice::sole();
    $receivableBefore = ($this->balanceOn)('accounts_receivable');

    // ⚠ 8,500 is owed. Taking 20,000 against it would leave the customer with a
    // negative receivable - money the business owes back - recorded as if the
    // invoice had been settled.
    $this->actingAs($this->user)->post("/sales/invoices/{$invoice->id}/receipts", [
        'money_account_id' => $this->till->id,
        'amount' => '20000.00',
        'received_on' => '2026-08-09',
    ])->assertSessionHasErrors();

    expect(($this->balanceOn)('accounts_receivable'))->toBe($receivableBefore);
});

// ── CRM lead capture, through the real HTTP layer ───────────────────────────

it('captures a lead with its attribution and touches no ledger', function () {
    $source = LeadSource::create([
        'business_id' => $this->business->id, 'code' => 'DUMMY-FB', 'name' => 'DUMMY Facebook',
    ]);

    // ⚠⚠ Recorded BEFORE, then compared. An earlier draft of this test asserted
    // Journal::count() against itself, which is true whatever the code does -
    // exactly the shape of the 16-test suite that once passed for an accidental
    // reason on this project.
    $journalsBefore = Journal::count();

    $this->actingAs($this->user)->post('/crm/leads', [
        'name' => 'DUMMY Rahim Traders',
        'phone' => '01712345678',
        'status' => LeadStatus::New->value,
        'lead_source_id' => $source->id,
        'utm_campaign' => 'dummy-eid-honey',
        'note' => 'Asked about honey in bulk',
    ])->assertRedirect();

    $lead = Lead::sole();

    expect($lead->name)->toBe('DUMMY Rahim Traders')
        ->and($lead->status)->toBe(LeadStatus::New);

    // ⚠ Attribution is its own append-only row, not columns on the lead: where
    // somebody came from is a fact about an event, and a lead can be touched by
    // more than one campaign.
    $attribution = CrmAttribution::sole();

    expect($attribution->lead_source_id)->toBe($source->id)
        ->and($attribution->utm_campaign)->toBe('dummy-eid-honey');

    // ⚠⚠ A lead is a person who MIGHT buy something, not a transaction. Nothing
    // about capturing one may reach the books.
    expect(Journal::count())->toBe($journalsBefore);
});

it('refuses to let a lead be marked converted by hand', function () {
    // ⚠⚠ `converted` asserts that a customer record exists. Allowing it on this
    // form would let somebody claim a conversion with nothing behind it, and
    // every conversion figure the business ever reads would be unreliable.
    $this->actingAs($this->user)->post('/crm/leads', [
        'name' => 'DUMMY Optimist',
        'status' => 'converted',
    ])->assertSessionHasErrors('status');

    expect(Lead::count())->toBe(0);
});

// ── Staff boundaries, across every module at once ───────────────────────────

it('keeps a Staff member out of every decision that moves or reveals money', function (string $method, string $uri) {
    $response = ($this->asRole)('Staff')->{$method}($uri, []);

    // ⚠ 403 is the answer being asserted. A 302 back to a form would mean the
    // route let the request in and something else stopped it, which is a
    // different guarantee.
    expect($response->status())->toBe(403, "{$method} {$uri} did not refuse Staff");
})->with([
    'record an expense' => ['post', '/purchasing/expenses'],
    'pay a supplier' => ['post', '/purchasing/payments'],
    'see the asset register' => ['get', '/assets'],
    'record an asset' => ['post', '/assets'],
    'run depreciation' => ['post', '/assets/depreciation'],
    'set a selling price' => ['post', '/sales/prices'],
]);

it('still lets a Staff member do the operational work of the day', function () {
    // ⚠ The boundary is only meaningful if the other side of it is open. Staff
    // who cannot do their job route around the system.
    ($this->asRole)('Staff')->get('/purchasing/expenses')->assertOk();
    ($this->asRole)('Staff')->get('/inventory/products')->assertOk();
    ($this->asRole)('Staff')->get('/sales/counter')->assertOk();
});
