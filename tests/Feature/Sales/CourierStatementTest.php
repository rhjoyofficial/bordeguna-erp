<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
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
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier settlement slice C2: a courier's payout statement clears the cash on
 * delivery it was holding, costs its fees, and lands this business's share of
 * the payout. One Pathao account serves both brands (Joy, 13 September).
 */
beforeEach(function () {
    // The courier's original file is kept on the private disk (v2).
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

    Carbon::setTestNow('2026-09-14 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id, 'is_active' => true,
    ]);

    // A parcel of one jar, handed to Pathao and answered.
    $this->parcel = function (string $number, ConsignmentStatus $outcome = ConsignmentStatus::Delivered, bool $answered = true): Consignment {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => '1']],
            createdBy: $this->owner,
        );
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '1', 'orderLine' => $order->lines->first()]],
            createdBy: $this->owner,
            deliveredAt: '2026-09-12 11:00:00',
        );
        $consignment = app(BookConsignment::class)->handle($delivery, $this->courier, $number, '850', $this->owner);

        if ($answered) {
            app(RecordConsignmentOutcome::class)->handle(
                consignment: $consignment,
                outcome: $outcome,
                on: '2026-09-13',
                collectedAmount: $outcome === ConsignmentStatus::Returned ? '0' : '850',
                lines: [],
                recordedBy: $this->owner,
            );
        }

        return $consignment->fresh();
    };

    // Pathao's CSV, with its real columns. Final fee and payout are worked out
    // the way Pathao works them unless a row says otherwise.
    $this->csv = function (array $rows): string {
        $out = ['Consignment_ID,Created_Date,Invoice type,Collected_Amount,Recipient_Name,Recipient_Phone,Collectable_Amount,COD_fee,Delivery_Fee,Final_Fee,Discount,Additional_Charge,Compensation_Cost,Promo_Discount,Payout,Merchant_Order_ID,Store_name'];

        foreach ($rows as $row) {
            $row += ['collected' => 0, 'fee' => 90, 'discount' => 0, 'cod' => 0, 'additional' => 0, 'promo' => 0, 'compensation' => 0, 'reference' => 'N/A'];
            $final = $row['final'] ?? $row['fee'] - $row['discount'] + $row['cod'] + $row['additional'];
            $payout = $row['payout'] ?? $row['collected'] - $final;
            $f = fn ($n) => number_format((float) $n, 2, '.', '');

            $out[] = implode(',', [
                $row['number'], '2026-09-12 10:00:00', 'delivery', $f($row['collected']), 'Rahima Test', '01700000000',
                $f($row['collected']), $f($row['cod']), $f($row['fee']), $f($final), $f($row['discount']), $f($row['additional']),
                $f($row['compensation']), $f($row['promo']), $f($payout), $row['reference'], 'Bionic',
            ]);
        }

        return implode("\n", $out)."\n";
    };

    $this->import = fn (array $rows, string $reference = '230826BXGIYJJ', ?MoneyAccount $courier = null): CourierStatement => app(ImportCourierStatement::class)->handle(
        courier: $courier ?? $this->courier,
        reference: $reference,
        statementDate: '2026-09-14',
        contents: ($this->csv)($rows),
        filename: 'paid_invoice.csv',
        createdBy: $this->owner,
    );

    $this->post = fn (CourierStatement $statement, ?string $deposit = null, ?MoneyAccount $into = null): CourierStatement => app(PostCourierStatement::class)->handle(
        statement: $statement,
        receivedInto: $deposit === null ? null : ($into ?? $this->bank),
        depositAmount: $deposit,
        receivedOn: '2026-09-14',
        postedBy: $this->owner,
    );

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('clears what the courier held, costs its fees, and lands the share in the bank', function () {
    ($this->parcel)('DBDELIVERED1');
    ($this->parcel)('DBRETURNED1', ConsignmentStatus::Returned);

    $statement = ($this->import)([
        ['number' => 'DBDELIVERED1', 'collected' => 850, 'fee' => 70, 'discount' => 20, 'cod' => 4.25],
        ['number' => 'DBRETURNED1', 'fee' => 110, 'discount' => 20],
        ['number' => 'DBHANDBOOKED', 'fee' => 90],
    ]);

    CourierStatementLine::query()->where('consignment_number', 'DBHANDBOOKED')->update(['resolution' => CourierStatementLine::MINE]);

    // 850 - 54.25, less 90 for the return, less 90 for the hand-booked parcel.
    ($this->post)($statement, '615.75');

    $statement->refresh();

    expect($statement->status)->toBe(CourierStatement::POSTED)
        ->and((string) $statement->share_amount)->toBe('615.7500')
        // The 850 the courier held is cleared.
        ->and(($this->posted)('courier_receivable'))->toBe(0)
        ->and(($this->posted)('courier_charge'))->toBe(14000)
        ->and(($this->posted)('customer_return_delivery'))->toBe(9000)
        ->and(($this->posted)('cod_handling_charge'))->toBe(425)
        ->and(($this->posted)('cash_at_bank'))->toBe(61575)
        ->and(CourierParcelClaim::query()->count())->toBe(3)
        ->and(CourierStatementLine::query()->where('consignment_number', 'DBDELIVERED1')->value('consignment_id'))->not->toBeNull();
});

it('will not post until every parcel the ERP does not know is decided', function () {
    ($this->parcel)('DBDELIVERED1');
    $statement = ($this->import)([
        ['number' => 'DBDELIVERED1', 'collected' => 850, 'fee' => 70],
        ['number' => 'DBHANDBOOKED', 'fee' => 90],
    ]);

    expect(CourierStatementReview::of($statement)->problems[0])->toContain('DBHANDBOOKED');

    expect(fn () => ($this->post)($statement, '690'))->toThrow(ValidationException::class, 'DBHANDBOOKED');

    CourierStatementLine::query()->where('consignment_number', 'DBHANDBOOKED')->update(['resolution' => CourierStatementLine::NOT_MINE]);

    // Not ours: left out, so the share is the delivered parcel alone.
    ($this->post)($statement->fresh(), '690');

    expect((string) $statement->fresh()->share_amount)->toBe('780.0000')
        ->and(($this->posted)('cash_at_bank'))->toBe(78000);
});

it('refuses to claim a parcel the ERP does not know when it collected money', function () {
    $statement = ($this->import)([['number' => 'PC-200826001', 'collected' => 1059, 'fee' => 70, 'discount' => 20, 'reference' => 'pc200826001']]);
    CourierStatementLine::query()->update(['resolution' => CourierStatementLine::MINE]);

    expect(fn () => ($this->post)($statement->fresh(), '1009'))
        ->toThrow(ValidationException::class, 'a sale the ERP has no record of');

    expect(JournalLine::query()->where('account_id', Account::bySlug('cash_at_bank')->id)->count())->toBe(0);
});

it('refuses a deposit that does not match the statement', function () {
    ($this->parcel)('DBDELIVERED1');
    $statement = ($this->import)([['number' => 'DBDELIVERED1', 'collected' => 850, 'fee' => 70]]);

    expect(fn () => ($this->post)($statement, '700'))->toThrow(ValidationException::class, 'but');

    expect($statement->fresh()->status)->toBe(CourierStatement::DRAFT);
});

it('owes the courier when a statement pays nothing out and its fees outweigh the cash', function () {
    ($this->parcel)('DBRETURNED1', ConsignmentStatus::Returned);
    $statement = ($this->import)([['number' => 'DBRETURNED1', 'fee' => 90]]);

    ($this->post)($statement);

    // No money arrived: the return fee is a cost, and the courier is owed it.
    // v2 (ChatGPT ruling 6): owed on Payable to Couriers, never a credit in 1145.
    expect(($this->posted)('customer_return_delivery'))->toBe(9000)
        ->and(($this->posted)('courier_receivable'))->toBe(0)
        ->and(($this->posted)('courier_payable'))->toBe(-9000)
        ->and(($this->posted)('cash_at_bank'))->toBe(0)
        ->and($statement->fresh()->deposit_amount)->toBeNull();
});

it('refuses a parcel the ERP still shows as with the courier, or one whose collection disagrees', function () {
    ($this->parcel)('DBBOOKED1', answered: false);
    ($this->parcel)('DBDELIVERED1');

    $statement = ($this->import)([
        ['number' => 'DBBOOKED1', 'collected' => 850, 'fee' => 70],
        ['number' => 'DBDELIVERED1', 'collected' => 800, 'fee' => 70],
    ]);

    $problems = CourierStatementReview::of($statement)->problems;

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toContain('still recorded as with the courier')
        ->and($problems[1])->toContain('the ERP recorded');
});

it('books only its own share when two brands share one courier account, and settles a parcel once', function () {
    ($this->parcel)('DBBRANDA1');

    // The other brand: its own business, its own row for the same Pathao account.
    $other = Business::create(['name' => 'Other Brand', 'slug' => 'other-brand']);
    app(BusinessContext::class)->set($other->id);
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($other->id);
    $otherWarehouse = Warehouse::create(['business_id' => $other->id, 'name' => 'Other', 'code' => 'OTH']);
    $otherCustomer = Customer::create(['business_id' => $other->id, 'name' => 'Other buyer', 'type' => CustomerType::Retail]);
    $otherDelivery = Delivery::create(['business_id' => $other->id, 'customer_id' => $otherCustomer->id, 'warehouse_id' => $otherWarehouse->id, 'delivery_number' => 'DC-OTHER-1', 'delivered_at' => '2026-09-12 10:00:00', 'created_by' => $this->owner->id]);
    $otherCourier = MoneyAccount::create(['business_id' => $other->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier, 'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true]);
    $otherBank = MoneyAccount::create(['business_id' => $other->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank, 'account_id' => Account::bySlug('cash_at_bank')->id, 'is_active' => true]);
    Consignment::create(['business_id' => $other->id, 'delivery_id' => $otherDelivery->id, 'money_account_id' => $otherCourier->id, 'consignment_number' => 'DBBRANDB1', 'cod_amount' => '1200', 'status' => ConsignmentStatus::Delivered, 'collected_amount' => '1200', 'outcome_on' => '2026-09-13', 'created_by' => $this->owner->id]);

    $rows = [
        ['number' => 'DBBRANDA1', 'collected' => 850, 'fee' => 70],
        ['number' => 'DBBRANDB1', 'collected' => 1200, 'fee' => 110],
    ];

    // Brand A: B's parcel is recognised as B's, and only A's share is booked.
    app(BusinessContext::class)->set($this->business->id);
    $forA = ($this->import)($rows);
    $review = CourierStatementReview::of($forA);

    expect($review->rows->firstWhere('line.consignment_number', 'DBBRANDB1')['state'])->toBe(CourierStatementReview::ELSEWHERE)
        ->and($review->share->toTaka())->toBe('780.00');

    ($this->post)($forA, '1870.00');

    // Brand B imports the same payout and books its own share.
    app(BusinessContext::class)->set($other->id);
    $forB = ($this->import)($rows, courier: $otherCourier);
    ($this->post)($forB, '1870.00', $otherBank);

    expect((int) JournalLine::withoutGlobalScopes()->where('account_id', Account::bySlug('cash_at_bank')->id)->sum('amount_minor'))->toBe(187000);

    // The same file again is refused outright (v2: one file, one statement).
    app(BusinessContext::class)->set($this->business->id);
    expect(fn () => ($this->import)($rows, 'SAME-PAYOUT-AGAIN'))->toThrow(ValidationException::class, 'already imported');

    // And A cannot settle its parcel a second time on another file either.
    $again = ($this->import)([...$rows, ['number' => 'DBSOMETHINGNEW', 'fee' => 60]], 'SAME-PAYOUT-AGAIN');

    expect(CourierStatementReview::of($again)->problems[0])->toContain('already been paid out');
});

it('keeps a row that does not add up as a row error, and refuses a file that lacks a column', function () {
    expect(fn () => app(ImportCourierStatement::class)->handle($this->courier, 'X1', '2026-09-14', "Consignment_ID,Payout\nDB1,10\n", 'x.csv', $this->owner))
        ->toThrow(ValidationException::class, 'not a Pathao invoice CSV');

    expect(CourierStatement::query()->count())->toBe(0);

    // v2: the bad row is kept as an error, the good one is read, and the draft
    // cannot be posted until the file is fixed.
    $statement = ($this->import)([
        ['number' => 'DB1', 'collected' => 850, 'fee' => 70, 'payout' => 790],
        ['number' => 'DB2', 'fee' => 70],
    ]);

    expect($statement->lines->pluck('consignment_number')->all())->toBe(['DB2'])
        ->and($statement->rowErrors())->toHaveCount(1)
        ->and($statement->rowErrors()[0]['row'])->toBe(2)
        ->and($statement->rowErrors()[0]['message'])->toContain('does not add up')
        ->and(CourierStatementReview::of($statement)->problems[0])->toContain('could not be read');
});

it('lists what a courier has not paid out yet, and what the books say it holds', function () {
    ($this->parcel)('DBPAID1');
    ($this->parcel)('DBWAITING1');
    ($this->parcel)('DBSTILLOUT1', answered: false);

    $statement = ($this->import)([['number' => 'DBPAID1', 'collected' => 850, 'fee' => 70]]);
    ($this->post)($statement, '780');

    $props = fn (string $query) => $this->actingAs($this->owner)->get('/sales/consignments'.$query)->original->getData()['page']['props'];

    $awaiting = $props('?status=awaiting_payout');

    expect(collect($awaiting['consignments']['data'])->pluck('consignment_number')->all())->toBe(['DBWAITING1'])
        ->and($awaiting['consignments']['data'][0]['waiting_days'])->toBe(1)
        // One 850 parcel still owed; the paid one cleared from both views.
        ->and($awaiting['couriers'][0]['awaiting_payout'])->toBe('850.00')
        ->and($awaiting['couriers'][0]['held_in_books'])->toBe('850.00');

    $paid = collect($props('?status=all')['consignments']['data'])->firstWhere('consignment_number', 'DBPAID1');

    expect($paid['paid_out_on']['statement_reference'])->toBe('230826BXGIYJJ');
});

it('imports through the screen, keeps no recipient name or phone, and discards only a draft', function () {
    ($this->parcel)('DBDELIVERED1');

    $this->actingAs($this->owner)->post('/sales/courier-statements', [
        'money_account_id' => $this->courier->id,
        'statement_reference' => '230826BXGIYJJ',
        'statement_date' => '2026-09-14',
        'file' => UploadedFile::fake()->createWithContent('paid_invoice.csv', ($this->csv)([['number' => 'DBDELIVERED1', 'collected' => 850, 'fee' => 70]])),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $statement = CourierStatement::query()->sole();
    $stored = json_encode(CourierStatementLine::query()->get()->toArray());

    expect($stored)->not->toContain('Rahima')->not->toContain('01700000000');

    $props = $this->actingAs($this->owner)->get('/sales/courier-statements/'.$statement->id)->original->getData()['page']['props'];

    expect($props['summary']['share'])->toBe('780.00')
        ->and($props['can_post'])->toBeTrue();

    $this->actingAs($this->owner)->post("/sales/courier-statements/{$statement->id}/post", [
        'received_money_account_id' => $this->bank->id, 'deposit_amount' => '780', 'received_on' => '2026-09-14',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->delete('/sales/courier-statements/'.$statement->id)->assertSessionHasErrors('statement');

    expect(CourierStatement::query()->count())->toBe(1);
});
