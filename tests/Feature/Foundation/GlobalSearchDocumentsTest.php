<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 1, Slice 2 (master plan §11): the search box learns DOCUMENT NUMBERS
 * and PHONE NUMBERS - "INV-2026-00923" lands on the invoice, a half-remembered
 * phone number finds the customer at the counter. Same law as the original
 * header search: every group wears the permission its destination screen
 * enforces, so search can never show what a click would refuse.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'DocSearch Co', 'slug' => 'docsearch-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach ([
        'purchasing.reports.view', 'sales.reports.view', 'accounting.view', 'crm.leads.view', 'crm.leads.view.all',
    ] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'DocOwner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        // ⚠ view.all since 15 Sep 2026: these leads are unassigned, and only an
        // Owner-like reader sees those. LeadVisibilityTest owns who sees what.
        ->givePermissionTo(['purchasing.reports.view', 'sales.reports.view', 'accounting.view', 'crm.leads.view', 'crm.leads.view.all']);
    Role::firstOrCreate(['name' => 'DocNobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('DocOwner');

    $this->supplierId = DB::table('suppliers')->insertGetId([
        'business_id' => $this->business->id,
        'code' => 'DOCSUP', 'name' => 'Doc Supplier', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->moneyAccountId = DB::table('money_accounts')->insertGetId([
        'business_id' => $this->business->id,
        'name' => 'Doc Bank', 'type' => 'bank',
        'account_id' => DB::table('accounts')->insertGetId([
            'code' => 'D999', 'name' => 'Doc Bank GL', 'type' => 'asset', 'is_postable' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'currency' => 'BDT', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->warehouseId = DB::table('warehouses')->insertGetId([
        'business_id' => $this->business->id,
        'code' => 'DOCWH', 'name' => 'Doc Warehouse', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->nobody = User::factory()->create();
    $this->nobody->businesses()->attach($this->business->id);
    $this->nobody->assignRole('DocNobody');
});

function docSearch(User $user, string $q): array
{
    return test()->actingAs($user)
        ->withSession(['current_business_id' => test()->business->id])
        ->getJson(route('search', ['q' => $q]))
        ->json('groups');
}

function groupTitles(array $groups): array
{
    return array_column($groups, 'title');
}

it('finds a purchase order by its number and lands on the order itself', function () {
    $id = DB::table('purchase_orders')->insertGetId([
        'business_id' => $this->business->id,
        'po_number' => 'PO-2026-00042',
        'supplier_id' => $this->supplierId,
        'warehouse_id' => $this->warehouseId,
        'status' => 'draft',
        'order_date' => now()->toDateString(),
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $groups = docSearch($this->owner, 'PO-2026-00042');

    expect(groupTitles($groups))->toContain('Purchase orders');
    $group = collect($groups)->firstWhere('title', 'Purchase orders');
    expect($group['items'][0]['url'])->toBe('/purchasing/purchase-orders/'.$id);

    // ...and never to somebody whose role cannot open the purchasing screens.
    expect(groupTitles(docSearch($this->nobody, 'PO-2026-00042')))->not->toContain('Purchase orders');
});

it('finds a sales order by its number', function () {
    $id = DB::table('sales_orders')->insertGetId([
        'business_id' => $this->business->id,
        'order_number' => 'SO-2026-00007',
        'ordered_at' => now(),
        'customer_id' => Customer::create([
            'business_id' => $this->business->id,
            'code' => 'CUS-0042', 'name' => 'Order Customer', 'type' => 'retail',
        ])->id,
        'warehouse_id' => $this->warehouseId,
        'status' => 'draft',
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $groups = docSearch($this->owner, 'SO-2026-00007');
    $group = collect($groups)->firstWhere('title', 'Sales orders');

    expect($group)->not->toBeNull()
        ->and($group['items'][0]['url'])->toBe('/sales/orders/'.$id);
});

it('finds a journal by its number, only for accounting eyes', function () {
    $id = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id,
        'journal_number' => 'JV-2026-27-0099',
        'journal_type' => 'manual',
        'journal_date' => '2026-09-01',
        'memo' => 'Search me',
        'posted_by' => $this->owner->id,
        'posted_at' => now(),
        'created_at' => now(),
    ]);

    $groups = docSearch($this->owner, 'JV-2026-27-0099');
    $group = collect($groups)->firstWhere('title', 'Journal register');

    expect($group)->not->toBeNull()
        ->and($group['items'][0]['url'])->toBe('/accounting/journals/'.$id);

    expect(groupTitles(docSearch($this->nobody, 'JV-2026-27-0099')))->not->toContain('Journal register');
});

it('finds an expense voucher by its reference', function () {
    DB::table('expense_vouchers')->insert([
        'business_id' => $this->business->id,
        'voucher_number' => 'EXP-2026-27-0001',
        'reference' => 'NIKOLA-2026-09',
        'spent_on' => '2026-08-31',
        'total' => 4900,
        'money_account_id' => $this->moneyAccountId,
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $groups = docSearch($this->owner, 'NIKOLA-2026-09');
    $group = collect($groups)->firstWhere('title', 'Expenses');

    expect($group)->not->toBeNull()
        ->and($group['items'][0]['url'])->toContain('/purchasing/expenses');
});

it('finds a lead by name or phone, gated on the CRM permission', function () {
    DB::table('leads')->insert([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'phone' => '01911222333',
        'status' => 'new',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(collect(docSearch($this->owner, 'Karim Traders'))->firstWhere('title', 'Leads'))->not->toBeNull()
        ->and(collect(docSearch($this->owner, '01911222333'))->firstWhere('title', 'Leads'))->not->toBeNull()
        ->and(groupTitles(docSearch($this->nobody, 'Karim Traders')))->not->toContain('Leads');
});

it('finds a customer by phone number - the counter lookup', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0009',
        'name' => 'Rahim Store',
        'type' => 'retail',
        'phone' => '01712345678',
    ]);

    $groups = docSearch($this->owner, '01712345678');
    $group = collect($groups)->firstWhere('title', 'Customers');

    expect($group)->not->toBeNull()
        ->and($group['items'][0]['label'])->toBe('Rahim Store');
});
