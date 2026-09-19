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
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * VAT on a sales invoice (ACCOUNTING-COMPLETION-AND-VAT-PLAN-2026-09-20.md).
 *
 * Joy, 19 September: "vat inclusive also exclusive both cause not fixed always
 * will be one, so both." Every case is therefore proved both ways round, and
 * every journal is checked to net to zero with the VAT in its own account.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Vat Co', 'slug' => 'vat-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new TaxAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['STANDARD' => 'Standard', 'ZERO' => 'Zero-rated', 'EXEMPT' => 'Exempt'] as $code => $name) {
        $this->{strtolower($code)} = TaxClass::create(['business_id' => $this->business->id, 'code' => $code, 'name' => $name]);
    }
    TaxRate::create([
        'business_id' => $this->business->id, 'tax_class_id' => $this->standard->id,
        'rate' => '15', 'effective_from' => '2026-01-01',
    ]);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->makeProduct = fn (string $sku, ?int $taxClassId = null) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->unit->id,
        'sku' => $sku, 'name' => $sku, 'type' => ProductType::FinishedGood, 'tax_class_id' => $taxClassId,
    ]);
    $this->honey = ($this->makeProduct)('HONEY');
    $this->rice = ($this->makeProduct)('RICE', $this->zero->id);

    $this->customer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Distributor,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-10-05 10:00:00');

    $this->vatOn = function (string $from = '2026-10-01') {
        $this->business->forceFill(['vat_enabled' => true, 'vat_effective_from' => $from, 'vat_regime' => 'vat_standard'])->save();
    };

    // Stock in, deliver, and hand back the delivery lines ready to invoice.
    $this->delivered = function (array $items) {
        foreach ($items as [$product, $qty]) {
            app(RecordStockIn::class)->handle($product, $this->warehouse, $qty, '100', $this->user, occurredAt: '2026-09-01 09:00:00');
        }

        return app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: array_map(fn ($item) => ['product' => $item[0], 'quantity' => $item[1]], $items),
            createdBy: $this->user,
        )->lines;
    };

    $this->invoice = fn (array $lines, string $discount = '0', ?bool $inclusive = null, mixed $on = null) => app(RecordSalesInvoice::class)
        ->handle($this->customer, $lines, $this->user, $on, $discount, null, null, $inclusive);

    $this->posted = function (SalesInvoice $invoice): array {
        $journal = Journal::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->sole();
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

it('changes nothing about an invoice while VAT is switched off', function () {
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']], '500');
    $posted = ($this->posted)($invoice);

    expect((float) $invoice->total)->toBe(9500.0)
        ->and((float) $invoice->tax_total)->toBe(0.0)
        ->and($invoice->prices_include_tax)->toBeFalse()
        ->and($posted['1140'])->toBe(950000)
        ->and($posted['4100'])->toBe(-1000000)
        ->and($posted['4210'])->toBe(50000)
        ->and($posted)->not->toHaveKey('2170');
});

it('adds VAT on top when prices exclude it', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']], '0', false);
    $posted = ($this->posted)($invoice);

    expect((float) $invoice->subtotal)->toBe(10000.0)
        ->and((float) $invoice->tax_total)->toBe(1500.0)
        ->and((float) $invoice->total)->toBe(11500.0)
        ->and($posted['1140'])->toBe(1150000)
        ->and($posted['4100'])->toBe(-1000000)
        ->and($posted['2170'])->toBe(-150000);
});

it('takes VAT out of the price when prices include it, and the customer pays exactly the price', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1150']], '0', true);
    $posted = ($this->posted)($invoice);

    expect((float) $invoice->total)->toBe(11500.0)
        ->and((float) $invoice->tax_total)->toBe(1500.0)
        ->and($invoice->prices_include_tax)->toBeTrue()
        ->and($posted['1140'])->toBe(1150000)
        ->and($posted['4100'])->toBe(-1000000)
        ->and($posted['2170'])->toBe(-150000);
});

it('taxes what is left after a whole-invoice discount, exclusive', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']], '1000', false);
    $posted = ($this->posted)($invoice);

    // 10,000 less 1,000 = 9,000 taxable; VAT 1,350; the customer owes 10,350.
    expect((float) $invoice->tax_total)->toBe(1350.0)
        ->and((float) $invoice->total)->toBe(10350.0)
        ->and($posted['4100'])->toBe(-1000000)
        ->and($posted['4210'])->toBe(100000)
        ->and($posted['2170'])->toBe(-135000);
});

it('posts revenue and the discount both net of VAT when an inclusive invoice is discounted', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1150']], '1150', true);
    $posted = ($this->posted)($invoice);

    // The customer pays 11,500 - 1,150 = 10,350, of which 1,350 is VAT.
    expect((float) $invoice->total)->toBe(10350.0)
        ->and((float) $invoice->tax_total)->toBe(1350.0)
        ->and($posted['1140'])->toBe(1035000)
        ->and($posted['4100'])->toBe(-1000000)
        ->and($posted['4210'])->toBe(100000)
        ->and($posted['2170'])->toBe(-135000);
});

it('keeps a zero-rated line zero-rated on a mixed, discounted invoice', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10'], [$this->rice, '10']]);

    $invoice = ($this->invoice)([
        ['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '600'],
        ['deliveryLine' => $lines[1], 'quantity' => '10', 'unitPrice' => '400'],
    ], '1000', false);
    ($this->posted)($invoice);

    [$honey, $rice] = $invoice->lines->sortBy('id')->values()->all();

    // The 1,000 discount falls 600 / 400; only the honey's 5,400 is taxed.
    expect((float) $honey->discount_share)->toBe(600.0)
        ->and((float) $rice->discount_share)->toBe(400.0)
        ->and((float) $honey->tax_amount)->toBe(810.0)
        ->and((float) $rice->tax_amount)->toBe(0.0)
        ->and($rice->tax_rate)->toBe('0.0000')
        ->and((float) $invoice->total)->toBe(9810.0);
});

it('freezes the rate onto the line, so a later rate change does not reach it', function () {
    ($this->vatOn)();
    $lines = ($this->delivered)([[$this->honey, '10']]);
    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']], '0', false);

    TaxRate::create([
        'business_id' => $this->business->id, 'tax_class_id' => $this->standard->id,
        'rate' => '7.5', 'effective_from' => '2026-10-01',
    ]);

    $line = $invoice->fresh('lines')->lines->first();

    expect($line->tax_rate)->toBe('15.0000')->and((float) $line->tax_amount)->toBe(1500.0);
});

it('charges no VAT on an invoice dated before VAT started, even with the switch on', function () {
    ($this->vatOn)('2026-10-01');
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']], '0', false, '2026-09-30');

    expect((float) $invoice->tax_total)->toBe(0.0)->and((float) $invoice->total)->toBe(10000.0);
});

it('follows the customer\'s price group when the clerk does not choose', function () {
    ($this->vatOn)();
    $this->business->forceFill(['prices_include_tax' => true])->save();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    $invoice = ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1150']]);

    expect($invoice->prices_include_tax)->toBeTrue()->and((float) $invoice->total)->toBe(11500.0);
});

it('refuses to invoice when VAT is on and no standard rate was ever set', function () {
    ($this->vatOn)();
    TaxRate::query()->delete();
    $lines = ($this->delivered)([[$this->honey, '10']]);

    expect(fn () => ($this->invoice)([['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => '1000']]))
        ->toThrow(ValidationException::class);
    expect(SalesInvoice::count())->toBe(0);
});
