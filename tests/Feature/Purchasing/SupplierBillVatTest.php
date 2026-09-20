<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\TaxAccountMappingSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * VAT on a supplier bill (VAT plan D7): claimable VAT goes to Input VAT and the
 * goods are carried net; VAT that may not be claimed stays in their cost.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Input Co', 'slug' => 'input-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new TaxAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $standard = TaxClass::create(['business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard']);
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $standard->id, 'rate' => '15', 'effective_from' => '2026-01-01']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-IN', 'name' => 'Bottle', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-10-05 09:00:00');

    $this->vatOn = fn () => $this->business->forceFill([
        'vat_enabled' => true, 'vat_effective_from' => '2026-10-01', 'vat_regime' => 'vat_standard',
    ])->save();

    // 10 received at 100 each: 1,000 sits in stock and in GRNI.
    $this->receivedOrder = function () {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '100']],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);
        $order = $order->fresh(['lines', 'approvalRequest']);
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '10']], $this->user);

        return $order->fresh('lines');
    };

    $this->bill = function (string $unitCost, bool $inclusive = false, array $extra = []) {
        $order = ($this->receivedOrder)();

        return app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier, billNumber: 'ACME/'.uniqid(), billDate: '2026-10-05',
            lines: [['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => $unitCost] + $extra],
            createdBy: $this->user, purchaseOrder: $order, pricesIncludeTax: $inclusive,
        );
    };

    $this->posted = function ($bill): array {
        $journal = Journal::where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->sole();
        $byCode = [];

        foreach (JournalLine::where('journal_id', $journal->id)->get() as $line) {
            $code = Account::find($line->account_id)->code;
            $byCode[$code] = ($byCode[$code] ?? 0) + $line->amount_minor;
        }

        expect(array_sum($byCode))->toBe(0);

        return $byCode;
    };
});

afterEach(fn () => Carbon::setTestNow());

it('changes nothing about a bill while VAT is off', function () {
    $bill = ($this->bill)('100');
    $posted = ($this->posted)($bill);

    expect((float) $bill->total)->toBe(1000.0)
        ->and((float) $bill->tax_total)->toBe(0.0)
        ->and($posted['2120'])->toBe(100000)
        ->and($posted['2110'])->toBe(-100000)
        ->and($posted)->not->toHaveKey('1185');
});

it('claims VAT added on top, and the goods stay at their net cost', function () {
    ($this->vatOn)();
    $bill = ($this->bill)('100', false);
    $posted = ($this->posted)($bill);

    // 1,000 of goods + 150 VAT: the supplier is owed 1,150, stock is untouched.
    expect((float) $bill->total)->toBe(1150.0)
        ->and((float) $bill->tax_total)->toBe(150.0)
        ->and($posted['2120'])->toBe(100000)
        ->and($posted['1185'])->toBe(15000)
        ->and($posted['2110'])->toBe(-115000)
        ->and($posted)->not->toHaveKey('5400');
});

it('takes claimable VAT out of a cost that already included it', function () {
    ($this->vatOn)();
    $bill = ($this->bill)('115', true);
    $posted = ($this->posted)($bill);

    expect((float) $bill->total)->toBe(1150.0)
        ->and((float) $bill->tax_total)->toBe(150.0)
        ->and($posted['2120'])->toBe(100000)
        ->and($posted['1185'])->toBe(15000)
        ->and($posted['2110'])->toBe(-115000);
});

it('leaves VAT that may not be claimed inside the cost of the goods', function () {
    ($this->vatOn)();
    $bill = ($this->bill)('100', false, ['taxRecoverable' => false]);
    $posted = ($this->posted)($bill);

    // Nothing to Input VAT: the 150 is a cost, and lands in the price variance.
    expect((float) $bill->total)->toBe(1150.0)
        ->and($posted)->not->toHaveKey('1185')
        ->and($posted['5400'])->toBe(15000)
        ->and($posted['2110'])->toBe(-115000);
});

it('takes the rate the supplier printed when it differs from ours', function () {
    ($this->vatOn)();
    $bill = ($this->bill)('100', false, ['taxRate' => '7.5']);

    expect((float) $bill->tax_total)->toBe(75.0)
        ->and($bill->lines->first()->tax_rate)->toBe('7.5000');
});

it('takes the VAT on a supplier credit note back out of input VAT', function () {
    ($this->vatOn)();
    $bill = ($this->bill)('100', false);
    $order = $bill->purchaseOrder()->with('lines')->first();

    $return = app(RecordPurchaseReturn::class)->handle(
        $order, [['line' => $order->lines->first(), 'quantity' => '2']], $this->user, 'Broken in transit',
    );

    // 2 of 10 back: 200 of goods + 30 VAT = 230 off what we owe.
    $note = app(RecordSupplierCreditNote::class)->handle(
        supplier: $this->supplier, purchaseReturn: $return, creditNoteNumber: 'CN-1', creditDate: '2026-10-05',
        allocations: [['bill' => $bill, 'amount' => '230']], createdBy: $this->user, taxAmount: '30',
    );
    $posted = ($this->posted)($note);

    expect($posted['2110'])->toBe(23000)
        ->and($posted['2120'])->toBe(-20000)
        ->and($posted['1185'])->toBe(-3000);
});
