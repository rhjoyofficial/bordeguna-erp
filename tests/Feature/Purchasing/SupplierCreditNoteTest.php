<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The supplier credit note: goods returned AFTER the bill arrived.
 *
 * ⚠⚠ Without this document the late return leaves GRNI in debit for ever and
 * the supplier's payable balance overstates what will actually be paid. The
 * credit note is Dr AP / Cr GRNI, allocated against specific bills, capped by
 * the return's billed value. See SUPPLIER-CREDIT-NOTE-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-10 10:00:00');

    $this->business = Business::create(['name' => 'Credit Co', 'slug' => 'credit-co']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500',
        'name' => 'Glass jar 500ml',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'name' => 'Dhaka Glassware',
        'code' => 'SUP-001',
    ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    approveIfPending($order->approvalRequest, $this->user);
    $this->order = $order->fresh(['lines', 'approvalRequest']);
    $this->line = $this->order->lines->first();

    // Receive 40 (1,000), get billed for all 40, THEN return 10 (250). GRNI:
    // +1,000 credit, -1,000 bill, -250 return = 250 DEBIT - the claim.
    app(RecordGoodsReceipt::class)->handle($this->order, [['line' => $this->line, 'quantity' => '40']], $this->user);
    $this->bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-9001',
        billDate: Carbon::parse('2026-08-10'),
        lines: [['line' => $this->line, 'quantity' => '40', 'unitCost' => '25.00']],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );
    $this->return = app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '10'],
    ], $this->user);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    $this->record = fn (string $amount = '250', string $number = 'CN-1') => app(RecordSupplierCreditNote::class)->handle(
        supplier: $this->supplier,
        purchaseReturn: $this->return,
        creditNoteNumber: $number,
        creditDate: '2026-08-10',
        allocations: [['bill' => $this->bill, 'amount' => $amount]],
        createdBy: $this->user,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('moves the late-return claim from GRNI onto the supplier balance', function () {
    // Before: the return left GRNI 250 in debit and AP still owes the full bill.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(25000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000);

    ($this->record)('250');

    // After: GRNI is a clean zero and the supplier is owed what will really
    // be paid. The bill's own outstanding says the same thing.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-75000)
        ->and($this->bill->fresh()->outstanding()->minor)->toBe(75000);

    // The AP debit carries the supplier as party - the subsidiary ledger rule.
    $debit = JournalLine::where('account_id', Account::bySlug('accounts_payable')->id)
        ->where('amount_minor', '>', 0)->first();
    expect($debit->party->is($this->supplier))->toBeTrue();
});

it('keeps the whole ledger and every subledger reconciled through the cycle', function () {
    ($this->record)('250');

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileAllBusinesses()['problems'])->toBeEmpty();
});

// ⚠⚠ C-SCN-2: the cap. Crediting more than the return's billed value is how
// a payable goes quietly negative.
it('refuses a credit note larger than the return left to credit', function () {
    ($this->record)('300');
})->throws(ValidationException::class, 'left to credit');

it('refuses a second credit once the return is fully credited', function () {
    ($this->record)('250', 'CN-1');
    ($this->record)('1', 'CN-2');
})->throws(ValidationException::class, 'left to credit');

it('refuses a credit beyond what the bill still owes', function () {
    // Pay 900 of the 1,000 first, so the bill owes less than the return's
    // 250 cap - the per-bill refusal must fire before the cap is reached.
    $cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $cash,
        paidOn: '2026-08-10',
        allocations: [['bill' => $this->bill, 'amount' => '900']],
        createdBy: $this->user,
    );

    ($this->record)('250');
})->throws(ValidationException::class, 'has only');

it('refuses a bill belonging to a different supplier', function () {
    $stranger = Supplier::create(['business_id' => $this->business->id, 'name' => 'Stranger', 'code' => 'SUP-X']);

    app(RecordSupplierCreditNote::class)->handle(
        supplier: $stranger,
        purchaseReturn: $this->return,
        creditNoteNumber: 'CN-1',
        creditDate: '2026-08-10',
        allocations: [['bill' => $this->bill, 'amount' => '100']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class);

it('posts exactly once however many times the posting is asked', function () {
    $creditNote = ($this->record)('250');

    app(PostPurchaseJournals::class)->forCreditNote($creditNote, $this->user);

    expect(Journal::where('source_type', $creditNote->getMorphClass())
        ->where('source_id', $creditNote->id)->count())->toBe(1);
});

it('is caught by the reconciliation when a credit total is corrupted', function () {
    $creditNote = ($this->record)('250');

    DB::table('supplier_credit_notes')->where('id', $creditNote->id)->update(['total' => '400.0000']);

    $problems = implode(' ', SubledgerReconciliation::reconcileAllBusinesses()['problems']);

    expect($problems)->toContain('Accounts Payable')
        ->and($problems)->toContain('Goods Received Not Invoiced');
});

describe('the screen', function () {
    beforeEach(function () {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
        }

        (new PurchasingPermissionsSeeder)->run();
        TenantScope::enter($this->business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

        $this->asRole = function (string $role) {
            $user = User::factory()->create();
            $user->businesses()->attach($this->business->id);
            setPermissionsTeamId($this->business->id);
            $user->assignRole($role);

            return $this->actingAs($user);
        };
    });

    it('records a credit note through the form', function () {
        ($this->asRole)('Manager')
            ->post('/purchasing/credit-notes', [
                'supplier_id' => $this->supplier->id,
                'purchase_return_id' => $this->return->id,
                'credit_note_number' => 'CN-9001',
                'credit_date' => '2026-08-10',
                'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '250']],
            ])
            ->assertRedirect();

        expect(SupplierCreditNote::where('credit_note_number', 'CN-9001')->exists())->toBeTrue()
            ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);
    });

    it('refuses a credit note dated in the future', function () {
        ($this->asRole)('Manager')
            ->post('/purchasing/credit-notes', [
                'supplier_id' => $this->supplier->id,
                'purchase_return_id' => $this->return->id,
                'credit_note_number' => 'CN-9003',
                'credit_date' => '2026-08-11',
                'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '250']],
            ])
            ->assertSessionHasErrors('credit_date');

        expect(SupplierCreditNote::where('credit_note_number', 'CN-9003')->exists())->toBeFalse();
    });

    // ⚠ A credit note reduces what leaves the bank; Staff may not record one,
    // by the same reasoning as payments.
    it('refuses Staff', function () {
        ($this->asRole)('Staff')
            ->post('/purchasing/credit-notes', [
                'supplier_id' => $this->supplier->id,
                'purchase_return_id' => $this->return->id,
                'credit_note_number' => 'CN-9002',
                'credit_date' => '2026-08-10',
                'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '250']],
            ])
            ->assertForbidden();
    });
});
