<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\BookConsignment;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\ImportCourierStatement;
use App\Modules\Sales\Actions\PostCourierStatement;
use App\Modules\Sales\Actions\RecordConsignmentOutcome;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierProvider;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\CourierStatements\CourierBalances;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier statements v2 (courier hub slices 7 + 8, 11 Oct 2026): CarryBee's
 * file, the courier from Settings > Couriers, row errors, one file one
 * statement, merchant-reference matching, net fees, compensation income,
 * payable to couriers, deposit differences, the exception workbook.
 *
 * ⚠ The CarryBee fixture is a SANITISED copy of a real invoice: real fee
 * arithmetic, invented consignment ids, references, names and phones.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Payout Co', 'slug' => 'payout-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-10-11 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '1000', 'effective_from' => '2026-01-01']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->carrybee = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->pathao = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao Courier', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id, 'is_active' => true,
    ]);

    // A parcel of one jar, handed to a courier and answered.
    $this->parcel = function (string $number, MoneyAccount $courier, string $collected = '1000', ConsignmentStatus $outcome = ConsignmentStatus::Delivered, ?string $shopReference = null, string $on = '2026-10-04', string $sent = '2026-10-03 11:00:00'): Consignment {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => '1']],
            createdBy: $this->owner,
        );

        if ($shopReference !== null) {
            $order->forceFill(['shop_reference' => $shopReference])->save();
        }

        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '1', 'orderLine' => $order->lines->first()]],
            createdBy: $this->owner,
            deliveredAt: $sent,
        );
        $consignment = app(BookConsignment::class)->handle($delivery, $courier, $number, $collected, $this->owner);

        app(RecordConsignmentOutcome::class)->handle(
            consignment: $consignment,
            outcome: $outcome,
            on: $on,
            collectedAmount: $outcome === ConsignmentStatus::Returned ? '0' : $collected,
            lines: [],
            recordedBy: $this->owner,
        );

        return $consignment->fresh();
    };

    // CarryBee's invoice CSV with its real columns; Total Fee and Billing are
    // worked out CarryBee's way unless a row says otherwise.
    $this->carrybeeCsv = function (array $rows, string $invoice = 'ITEST0001'): string {
        $out = ['Consignment_id,merchant_order_id,Recipient Name,Recipient Phone,Collectable Amount,Collected Amount,COD_fee,Delivery_fee,Additional_charge,Discount,Total Fee,Compensation,Adjustment,Billing_amount,Weight,Delivery_status,Delivered_by_hub_name,Created_date,Delivered_date,Invoiced_date,Invoice_number,Payout_method,Payment_status,Store_name'];
        $f = fn ($n) => number_format((float) $n, 2, '.', '');

        foreach ($rows as $row) {
            $row += ['collected' => 0, 'fee' => 100, 'discount' => 0, 'cod' => 0, 'additional' => 0, 'compensation' => 0, 'adjustment' => 0, 'reference' => '', 'status' => 'delivered', 'weight' => '0.70'];
            $total = $row['total'] ?? $row['fee'] - $row['discount'] + $row['cod'] + $row['additional'];
            $billing = $row['billing'] ?? $row['collected'] - $total + $row['compensation'] + $row['adjustment'];

            $out[] = implode(',', [
                $row['number'], $row['reference'], 'Rahima Test', '01799999999', $f($row['collected']), $f($row['collected']),
                $f($row['cod']), $f($row['fee']), $f($row['additional']), $f($row['discount']), $f($total),
                $f($row['compensation']), $f($row['adjustment']), $f($billing), $row['weight'], $row['status'], 'Test Hub',
                '2026-10-04', '2026-10-04', '2026-10-05', $invoice, 'bank', 'paid', 'Test Store',
            ]);
        }

        return implode("\n", $out)."\n";
    };

    $this->importCarryBee = fn (array $rows, string $invoice = 'ITEST0001', ?string $reference = null): CourierStatement => app(ImportCourierStatement::class)->handle(
        courier: $this->carrybee,
        reference: $reference,
        statementDate: '2026-10-05',
        contents: ($this->carrybeeCsv)($rows, $invoice),
        filename: 'carrybee.csv',
        createdBy: $this->owner,
    );

    $this->post = fn (CourierStatement $statement, ?string $deposit = null, array $extra = []): CourierStatement => app(PostCourierStatement::class)->handle(
        $statement,
        $deposit === null ? null : $this->bank,
        $deposit,
        '2026-10-06',
        $this->owner,
        ...$extra,
    );

    $this->posted = fn (string $slug, ?MoneyAccount $courier = null) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->when($courier !== null, fn ($query) => $query->where('money_account_id', $courier->id))
        ->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('reads the sanitised CarryBee invoice: the arithmetic holds, the reference comes from the file, and no recipient is kept', function () {
    $contents = file_get_contents(base_path('tests/fixtures/couriers/carrybee-invoice-sanitised.csv'));

    $statement = app(ImportCourierStatement::class)->handle($this->carrybee, null, '2026-10-05', $contents, 'carrybee.csv', $this->owner);

    expect($statement->statement_reference)->toBe('ITESTINVOICE1')
        ->and($statement->provider_code)->toBe('carrybee')
        ->and($statement->format)->toBe('carrybee_invoice_csv_v1')
        ->and($statement->rowErrors())->toBe([])
        ->and($statement->lines)->toHaveCount(6)
        ->and($statement->source_sha256)->toBe(hash('sha256', $contents))
        ->and(Storage::disk('local')->get($statement->source_path))->toBe($contents);

    $first = $statement->lines->firstWhere('consignment_number', 'FTEST000001');

    // Delivery 100 less discount 30 is the real charge: 70.
    expect((string) $first->delivery_fee)->toBe('100.0000')
        ->and((string) $first->discount)->toBe('30.0000')
        ->and((string) $first->net_fee)->toBe('70.0000')
        ->and((string) $first->payout)->toBe('1019.0000')
        ->and($first->hub_weight_grams)->toBe(700)
        ->and($first->delivery_status)->toBe('delivered')
        ->and($statement->fileTotal()->toTaka())->toBe('5723.64');

    $stored = json_encode([CourierStatementLine::query()->get()->toArray(), $statement->fresh()->toArray()]);

    expect($stored)->not->toContain('Test Recipient')->not->toContain('01700000001');
});

it('keeps a CarryBee row that does not add up as an error, imports the rest, and refuses a typed reference the file contradicts', function () {
    $statement = ($this->importCarryBee)([
        ['number' => 'FCB1', 'collected' => 1000, 'fee' => 100, 'discount' => 30, 'total' => 60],
        ['number' => 'FCB2', 'collected' => 1000, 'fee' => 100, 'compensation' => 50, 'billing' => 900],
        ['number' => 'FCB3', 'fee' => 70],
    ]);

    expect($statement->lines->pluck('consignment_number')->all())->toBe(['FCB3'])
        ->and(collect($statement->rowErrors())->pluck('consignment')->all())->toBe(['FCB1', 'FCB2'])
        ->and($statement->rowErrors()[0]['message'])->toContain('Total Fee')
        ->and($statement->rowErrors()[1]['message'])->toContain('Billing_amount');

    // The error report carries the rows, never a customer.
    $csv = $this->actingAs($this->owner)->get(route('sales.courier-statements.errors', $statement))->assertOk()->streamedContent();

    expect($csv)->toContain('FCB1')->toContain('Total Fee')->not->toContain('Rahima');

    expect(fn () => ($this->importCarryBee)([['number' => 'FCB9', 'fee' => 70]], 'ITEST0002', 'SOMETHING-ELSE'))
        ->toThrow(ValidationException::class, 'The file is invoice ITEST0002');
});

it('accepts Pathao promo discounts and compensation instead of refusing them', function () {
    $header = 'Consignment_ID,Created_Date,Invoice type,Collected_Amount,Recipient_Name,Recipient_Phone,Collectable_Amount,COD_fee,Delivery_Fee,Final_Fee,Discount,Additional_Charge,Compensation_Cost,Promo_Discount,Payout,Merchant_Order_ID,Store_name';
    $csv = implode("\n", [
        $header,
        // The promo comes off the fee (70 - 10 promo = 60 final), compensation on top.
        'DP1,2026-10-04,delivery,1000.00,Rahima Test,01700000000,1000.00,0.00,70.00,60.00,0.00,0.00,25.00,10.00,965.00,N/A,Bionic',
        // Promo shown as information only: the fee and payout ignore it.
        'DP2,2026-10-04,delivery,0.00,Rahima Test,01700000000,0.00,0.00,70.00,70.00,0.00,0.00,0.00,5.00,-70.00,N/A,Bionic',
    ])."\n";

    $statement = app(ImportCourierStatement::class)->handle($this->pathao, 'PATHAO-1', '2026-10-05', $csv, 'pathao.csv', $this->owner);

    $dp1 = $statement->lines->firstWhere('consignment_number', 'DP1');
    $dp2 = $statement->lines->firstWhere('consignment_number', 'DP2');

    expect($statement->rowErrors())->toBe([])
        ->and($statement->provider_code)->toBe('pathao')
        ->and((string) $dp1->promo_discount)->toBe('10.0000')
        ->and((string) $dp1->discount)->toBe('10.0000')
        ->and((string) $dp1->net_fee)->toBe('60.0000')
        ->and((string) $dp1->compensation)->toBe('25.0000')
        ->and((string) $dp2->promo_discount)->toBe('5.0000')
        ->and((string) $dp2->discount)->toBe('0.0000')
        ->and((string) $dp2->compensation)->toBe('0.0000');
});

it('refuses the same file twice for one courier, naming the first statement', function () {
    $rows = [['number' => 'FSAME1', 'fee' => 70]];
    $first = ($this->importCarryBee)($rows);

    expect(fn () => ($this->importCarryBee)($rows))
        ->toThrow(ValidationException::class, "already imported as CarryBee statement ITEST0001 (#{$first->id})");

    expect(CourierStatement::query()->count())->toBe(1);
});

it('takes the courier from Settings > Couriers before the typed provider, and normalises the typed one', function () {
    // Typed as something unreadable, linked to CarryBee in courier settings.
    $odd = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Bee account', 'type' => MoneyAccountType::Courier,
        'provider' => 'our bee guy', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    expect(CourierStatementFormats::for($odd))->toBeNull();

    $account = CourierAccount::create([
        'courier_provider_id' => CourierProvider::forCode('carrybee')->id, 'label' => 'LaLaDia', 'environment' => 'live',
        'base_url' => 'https://developers.carrybee.com',
    ]);
    CourierAccountBusiness::create(['courier_account_id' => $account->id, 'business_id' => $this->business->id, 'money_account_id' => $odd->id]);

    expect(CourierStatementFormats::provider($odd))->toBe('carrybee')
        ->and(CourierStatementFormats::provider($this->pathao))->toBe('pathao')
        ->and(CourierStatementFormats::normalise('  Carry Bee Ltd '))->toBe('carrybee')
        ->and(CourierParcelClaim::keyFor('Pathao Courier', 'dx1'))->toBe('pathao|DX1');
});

it('matches on the merchant order id when the consignment number differs, and only suggests by amount', function () {
    ($this->parcel)('ERP-TYPO-1', $this->carrybee, '1000', shopReference: 'TST-20261003-AAAA0001');
    ($this->parcel)('ERP-OTHER-2', $this->carrybee, '1000');

    $statement = ($this->importCarryBee)([
        ['number' => 'FTEST000001', 'reference' => '#TST-20261003-AAAA0001', 'collected' => 1000, 'fee' => 100, 'discount' => 30],
        ['number' => 'FUNKNOWN', 'collected' => 1000, 'fee' => 105, 'discount' => 15],
    ]);

    $review = CourierStatementReview::of($statement);
    $matched = $review->rows->firstWhere('line.consignment_number', 'FTEST000001');
    $unknown = $review->rows->firstWhere('line.consignment_number', 'FUNKNOWN');

    expect($matched['state'])->toBe(CourierStatementReview::OURS)
        ->and($matched['match_method'])->toBe(CourierStatementLine::BY_MERCHANT_REFERENCE)
        ->and($matched['consignment']->consignment_number)->toBe('ERP-TYPO-1')
        ->and($matched['note'])->toContain('ERP-TYPO-1')
        // Same amount is a suggestion for a person, never a match.
        ->and($unknown['state'])->toBe(CourierStatementReview::UNLISTED)
        ->and($unknown['suggestion'])->toContain('ERP-OTHER-2');

    CourierStatementLine::query()->where('consignment_number', 'FUNKNOWN')->update(['resolution' => CourierStatementLine::NOT_MINE]);

    // The whole payout arrives (930 + 910); this business books only its 930.
    ($this->post)($statement->fresh(), '1840');

    expect(($this->posted)('cash_at_bank'))->toBe(93000);

    $line = CourierStatementLine::query()->where('consignment_number', 'FTEST000001')->sole();

    expect($line->match_method)->toBe(CourierStatementLine::BY_MERCHANT_REFERENCE)
        ->and($line->consignment->consignment_number)->toBe('ERP-TYPO-1');
});

it('posts fees net of discount, compensation to its own income account and adjustments by sign, naming the courier', function () {
    ($this->parcel)('FNET1', $this->carrybee, '1000');

    // 1000 collected, fee 100 less 30, COD 5, compensation 50, adjustment -20.
    $statement = ($this->importCarryBee)([
        ['number' => 'FNET1', 'collected' => 1000, 'fee' => 100, 'discount' => 30, 'cod' => 5, 'compensation' => 50, 'adjustment' => -20],
    ]);

    // 1000 - 75 + 50 - 20 = 955.
    ($this->post)($statement, '955');

    expect(($this->posted)('courier_charge'))->toBe(7000)
        ->and(($this->posted)('cod_handling_charge'))->toBe(500)
        ->and(($this->posted)('courier_adjustment'))->toBe(2000)
        ->and(($this->posted)('courier_compensation_income', $this->carrybee))->toBe(-5000)
        ->and(($this->posted)('courier_compensation_income'))->toBe(-5000)
        ->and(($this->posted)('courier_receivable', $this->carrybee))->toBe(0)
        ->and(($this->posted)('cash_at_bank'))->toBe(95500)
        ->and(CourierBalances::compensation($this->carrybee)->toTaka())->toBe('50.00');

    // Every line on 1145, 2165 and 4960 names the courier.
    $courierHeads = Account::query()->whereIn('system_slug', ['courier_receivable', 'courier_payable', 'courier_compensation_income'])->pluck('id');

    expect(JournalLine::query()->whereIn('account_id', $courierHeads)->whereNull('money_account_id')->count())->toBe(0);
});

it('records a negative statement as payable to the courier, then nets it off a later payout', function () {
    ($this->parcel)('FRET1', $this->carrybee, '1000', ConsignmentStatus::Returned);

    // A return with a 340 charge and nothing collected: Bionic's outstanding -340.
    $negative = ($this->importCarryBee)([['number' => 'FRET1', 'fee' => 340]]);
    ($this->post)($negative);

    expect(($this->posted)('courier_payable', $this->carrybee))->toBe(-34000)
        ->and(($this->posted)('courier_receivable', $this->carrybee))->toBe(0)
        ->and(CourierBalances::payable($this->carrybee)->toTaka())->toBe('340.00');

    $courierHeads = Account::query()->whereIn('system_slug', ['courier_receivable', 'courier_payable'])->pluck('id');
    expect(JournalLine::query()->whereIn('account_id', $courierHeads)->where(fn ($q) => $q->whereNull('money_account_id')->orWhere('money_account_id', '!=', $this->carrybee->id))->count())->toBe(0);

    // The next payout: 1000 collected, fee 70; the courier keeps the 340.
    ($this->parcel)('FDEL2', $this->carrybee, '1000');
    $next = ($this->importCarryBee)([['number' => 'FDEL2', 'collected' => 1000, 'fee' => 70]], 'ITEST0002');

    expect(fn () => ($this->post)($next, '590', ['settlePayable' => '500']))
        ->toThrow(ValidationException::class, 'settle at most that');

    ($this->post)($next->fresh(), '590', ['settlePayable' => '340', 'bankReference' => 'BRAC-TT-778']);

    expect(CourierBalances::payable($this->carrybee)->toTaka())->toBe('0.00')
        ->and(($this->posted)('cash_at_bank'))->toBe(59000)
        ->and(($this->posted)('courier_receivable', $this->carrybee))->toBe(0)
        ->and((string) $next->fresh()->payable_settled_amount)->toBe('340.0000')
        ->and($next->fresh()->bank_reference)->toBe('BRAC-TT-778');
});

it('accepts a deposit that differs from the file only with a reason, keeps the shortfall on the courier, and stores the proof', function () {
    ($this->parcel)('FDIFF1', $this->carrybee, '1000');
    $statement = ($this->importCarryBee)([['number' => 'FDIFF1', 'collected' => 1000, 'fee' => 100]]);

    expect(fn () => ($this->post)($statement, '850'))->toThrow(ValidationException::class, 'say why they differ');

    ($this->post)($statement->fresh(), '850', [
        'differenceReason' => 'Courier held back 50 for a parcel under review',
        'bankReference' => 'NPSB-1234',
        'proof' => UploadedFile::fake()->create('advice.pdf', 20, 'application/pdf'),
    ]);

    $statement->refresh();

    expect(($this->posted)('cash_at_bank'))->toBe(85000)
        // 900 owed, 850 arrived: the courier still holds 50.
        ->and(($this->posted)('courier_receivable', $this->carrybee))->toBe(5000)
        ->and($statement->deposit_difference_reason)->toBe('Courier held back 50 for a parcel under review')
        ->and((string) $statement->deposit_amount)->toBe('850.0000')
        ->and($statement->bank_reference)->toBe('NPSB-1234')
        ->and($statement->attachmentInCollection(CourierStatement::PAYOUT_PROOF))->not->toBeNull();
});

it('keeps each courier company\'s balances separate on the shared heads', function () {
    ($this->parcel)('FSEP1', $this->carrybee, '1000', ConsignmentStatus::Returned);
    ($this->parcel)('DSEP1', $this->pathao, '1000', ConsignmentStatus::Returned);

    ($this->post)(($this->importCarryBee)([['number' => 'FSEP1', 'fee' => 120]]));

    $pathaoCsv = "Consignment_ID,Created_Date,Collected_Amount,Collectable_Amount,COD_fee,Delivery_Fee,Final_Fee,Discount,Additional_Charge,Compensation_Cost,Promo_Discount,Payout,Merchant_Order_ID\n"
        ."DSEP1,2026-10-04,0,0,0,80,80,0,0,0,0,-80,N/A\n";
    ($this->post)(app(ImportCourierStatement::class)->handle($this->pathao, 'P-SEP', '2026-10-05', $pathaoCsv, 'p.csv', $this->owner));

    expect(CourierBalances::payable($this->carrybee)->toTaka())->toBe('120.00')
        ->and(CourierBalances::payable($this->pathao)->toTaka())->toBe('80.00')
        ->and(($this->posted)('courier_payable'))->toBe(-20000);

    // The parcels list reads each courier's own 1145, not its payable.
    $couriers = collect($this->actingAs($this->owner)->get('/sales/consignments?status=all')->original->getData()['page']['props']['couriers'])->keyBy('name');

    expect($couriers['CarryBee']['owed_to_courier'])->toBe('120.00')
        ->and($couriers['Pathao']['owed_to_courier'])->toBe('80.00')
        ->and($couriers['CarryBee']['held_in_books'])->toBe('0.00');
});

it('posts a statement dated into a closed period on the first open day, with a note, never into the locked month', function () {
    ($this->parcel)('FLOCK1', $this->carrybee, '1000');
    ($this->parcel)('FLOCK2', $this->carrybee, '1000');
    $statement = ($this->importCarryBee)([['number' => 'FLOCK1', 'collected' => 1000, 'fee' => 100]]);
    $second = ($this->importCarryBee)([['number' => 'FLOCK2', 'collected' => 1000, 'fee' => 100]], 'ITEST0002');

    AccountingPeriod::closeThrough(Carbon::parse('2026-10-10'), $this->owner);

    $posted = ($this->post)($statement, '900');
    $journal = Journal::query()->where('source_type', $posted->getMorphClass())->where('source_id', $posted->id)->sole();

    expect($posted->status)->toBe(CourierStatement::POSTED)
        ->and($posted->received_on->toDateString())->toBe('2026-10-06')
        ->and($posted->posted_on->toDateString())->toBe('2026-10-11')
        ->and($posted->cutoff_note)->toContain('closed period')
        ->and($journal->journal_date->toDateString())->toBe('2026-10-11');

    // Closed through today: there is no open day yet, so it is refused.
    AccountingPeriod::closeThrough(Carbon::parse('2026-10-11'), $this->owner);

    expect(fn () => ($this->post)($second, '900'))->toThrow(PeriodClosedException::class);
});

it('refuses a line on Payable to Couriers or Courier Compensation Income that names no courier', function () {
    $draft = new JournalDraft(
        date: Carbon::parse('2026-10-06'),
        lines: [
            JournalLineDraft::debit(Account::bySlug('courier_charge')->id, Money::ofTaka('10')),
            JournalLineDraft::credit(Account::bySlug('courier_payable')->id, Money::ofTaka('10'), 'no courier'),
        ],
        memo: 'test',
    );

    expect(fn () => app(PostJournal::class)->handle($draft, $this->owner))
        ->toThrow(JournalException::class, 'names the courier');
});

it('downloads the exception workbook with every sheet, lists parcels missing from the file, and guards the original file', function () {
    ($this->parcel)('FWB1', $this->carrybee, '1000');
    // Answered long ago, on no statement: missing from the file.
    ($this->parcel)('FWBOLD', $this->carrybee, '1000', on: '2026-09-20', sent: '2026-09-18 10:00:00');

    $statement = ($this->importCarryBee)([
        ['number' => 'FWB1', 'collected' => 1000, 'fee' => 100],
        ['number' => 'FWBBAD', 'fee' => 100, 'total' => 1],
    ]);

    $response = $this->actingAs($this->owner)->get(route('sales.courier-statements.workbook', $statement))->assertOk();
    $file = $response->baseResponse->getFile()->getPathname();
    $book = IOFactory::load($file);

    expect($book->getSheetNames())->toBe(['Summary', 'Matched', 'Differences', 'Unmatched in file', 'Missing from file', 'Errors'])
        ->and($book->getSheetByName('Matched')->getCell('B2')->getFormattedValue())->toBe('FWB1')
        ->and($book->getSheetByName('Missing from file')->getCell('A2')->getFormattedValue())->toBe('FWBOLD')
        ->and($book->getSheetByName('Errors')->getCell('B2')->getFormattedValue())->toBe('FWBBAD');

    $this->actingAs($this->owner)->get(route('sales.courier-statements.source', $statement))->assertOk();

    $viewer = User::factory()->create();
    $viewer->businesses()->attach($this->business->id);
    $reader = Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    $reader->givePermissionTo('sales.reports.view');
    $viewer->assignRole($reader);

    $this->actingAs($viewer)->get(route('sales.courier-statements.show', $statement))->assertOk();
    $this->actingAs($viewer)->get(route('sales.courier-statements.source', $statement))->assertForbidden();
});
