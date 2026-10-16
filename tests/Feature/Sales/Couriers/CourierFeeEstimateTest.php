<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Fees\CourierFeeEstimator;
use App\Modules\Sales\Couriers\Fees\CourierFeeSamples;
use App\Modules\Sales\Couriers\Fees\CourierFeeVariance;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierFeeEstimate;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\Couriers\Models\CourierRateCard;
use App\Modules\Sales\Couriers\Models\CourierRateCardLine;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 5 (11 Oct 2026): what a parcel will cost at the courier,
 * said before booking and kept; the courier's quote and the statement's charge
 * beside it; and a calibrated card the Owner may approve. Most of these tests
 * are about what must not happen: a zero for a missing weight, a guess for an
 * unmapped place, a learned price from a returned or re-weighed parcel, an
 * estimate rewritten after the fact, a card changed once approved.
 */
const CFE_CB = 'https://developers.carrybee.com';
const CFE_PATHAO = 'https://api-hermes.pathao.com';

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');

    $this->business = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 600,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->unweighed = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => null,
        'sku' => 'SKU-OIL', 'name' => 'Mustard Oil 5L', 'type' => ProductType::FinishedGood,
    ]);

    foreach ([$this->product, $this->unweighed] as $product) {
        ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
        app(RecordStockIn::class)->handle($product, $this->warehouse, '200', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');
    }

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    $this->money = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
        'provider' => 'carrybee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    // Dhanmondi (Dhaka) and Panchagarh Sadar mapped for both couriers; Mirpur for neither.
    foreach ([['carrybee', '14', '101', '58', '901'], ['pathao', '1', '52', '60', '960']] as [$provider, $dhaka, $dhanmondi, $panchagarh, $sadar]) {
        CourierLocation::create(['provider' => $provider, 'level' => 'city', 'provider_location_id' => $dhaka, 'name' => 'Dhaka', 'synced_at' => now()]);
        CourierLocation::create(['provider' => $provider, 'level' => 'zone', 'provider_location_id' => $dhanmondi, 'parent_provider_id' => $dhaka, 'name' => 'Dhanmondi', 'synced_at' => now()]);
        CourierLocation::create(['provider' => $provider, 'level' => 'city', 'provider_location_id' => $panchagarh, 'name' => 'Panchagarh', 'synced_at' => now()]);
        CourierLocation::create(['provider' => $provider, 'level' => 'zone', 'provider_location_id' => $sadar, 'parent_provider_id' => $panchagarh, 'name' => 'Panchagarh Sadar', 'synced_at' => now()]);

        foreach ([['Dhaka', 'Dhanmondi', $dhaka, $dhanmondi], ['Panchagarh', 'Panchagarh Sadar', $panchagarh, $sadar]] as [$district, $thana, $city, $zone]) {
            CourierLocationMap::create([
                'provider' => $provider, 'district' => $district, 'thana' => $thana,
                'provider_city_id' => $city, 'provider_city_name' => $district, 'provider_zone_id' => $zone, 'provider_zone_name' => $thana,
                'status' => CourierLocationMap::APPLIED, 'source' => CourierLocationMap::MANUAL, 'confidence' => CourierLocationMap::HIGH,
            ]);
        }
    }

    Http::preventStrayRequests();
    $this->withoutVite();
});

afterEach(fn () => Carbon::setTestNow());

function cfeAccount(object $t, string $provider, array $options = []): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, $provider === 'carrybee' ? [
        'provider' => 'carrybee', 'label' => 'CB laladia', 'environment' => 'live', 'base_url' => CFE_CB,
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ] : [
        'provider' => 'pathao', 'label' => 'Pathao laladia', 'environment' => 'live', 'base_url' => CFE_PATHAO,
        'credentials' => ['client_id' => 'p-id', 'client_secret' => 'p-SECRET-1234', 'username' => 'ops@bionic.garden', 'password' => 'p-PASSWORD-99'],
    ]);

    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()] + ($options === [] ? [] : ['options' => array_merge($account->options ?? [], $options)]))->save();

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $t->business->id, 'provider_store_id' => $provider === 'pathao' ? '393838' : '24869',
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $t->money->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

function cfeChallan(object $t, string $quantity = '2', string $district = 'Dhaka', string $thana = 'Dhanmondi', ?Product $product = null): Delivery
{
    $product ??= $t->product;
    $order = app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $product, 'quantity' => $quantity]],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal),
    );
    $order->address()->create([
        'business_id' => $order->business_id, 'recipient_name' => 'Karim Uddin', 'recipient_phone' => '01711000000',
        'district' => $district, 'thana' => $thana, 'address_line' => 'House 4, Road 2, Jigatola',
    ]);

    return app(RecordDelivery::class)->handle(
        customer: $t->customer,
        warehouse: $t->warehouse,
        lines: [['product' => $product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $t->owner,
        deliveredAt: '2026-10-11 09:00:00',
    );
}

function cfeEstimate(CourierAccount $account, object $t, ?int $grams, string $district = 'Dhaka', string $thana = 'Dhanmondi', float $cod = 0, string $service = 'normal', array $info = [], string $quote = CourierFeeEstimator::QUOTE_OFF, ?string $on = null)
{
    return app(CourierFeeEstimator::class)->estimate($account, $t->business->id, ['district' => $district, 'thana' => $thana], $grams, $cod, $service, $info, $quote, $on);
}

/**
 * A parcel with a posted statement line charging `$net` (+ `$cod` COD fee).
 *
 * @param  array{hub?: ?int, status?: ConsignmentStatus, courier_status?: CourierStatus, estimate?: float, quote?: float}  $extra
 */
function cfeSample(object $t, int $n, float $net, int $declared = 1200, string $district = 'Panchagarh', string $thana = 'Panchagarh Sadar', float $cod = 0, array $extra = []): Consignment
{
    $delivery = cfeChallan($t, '1', $district, $thana);
    $consignment = Consignment::create([
        'delivery_id' => $delivery->id, 'money_account_id' => $t->money->id, 'consignment_number' => 'FA'.$n,
        'cod_amount' => '0', 'status' => $extra['status'] ?? ConsignmentStatus::Delivered, 'created_by' => $t->owner->id,
        'declared_weight_grams' => $declared, 'declared_weight_auto_grams' => $declared,
        'courier_status' => $extra['courier_status'] ?? CourierStatus::Delivered,
        'provider_fee_quoted' => isset($extra['quote']) ? (string) $extra['quote'] : null,
        'provider_cod_fee_quoted' => isset($extra['quote']) ? '0' : null,
    ]);

    $statement = CourierStatement::query()->firstOrCreate(
        ['money_account_id' => $t->money->id, 'statement_reference' => 'INV-1'],
        ['provider_code' => 'carrybee', 'statement_date' => '2026-10-11', 'status' => CourierStatement::POSTED, 'created_by' => $t->owner->id],
    );

    CourierStatementLine::create([
        'courier_statement_id' => $statement->id, 'row_number' => $n, 'consignment_number' => 'FA'.$n,
        'collectable_amount' => '0', 'collected_amount' => '0', 'delivery_fee' => (string) ($net + 25), 'discount' => '25',
        'net_fee' => (string) $net, 'cod_fee' => (string) $cod, 'additional_charge' => '0', 'final_fee' => (string) ($net + $cod),
        'payout' => '0', 'hub_weight_grams' => $extra['hub'] ?? $declared, 'consignment_id' => $consignment->id,
    ]);

    if (isset($extra['estimate'])) {
        CourierFeeEstimate::create([
            'delivery_id' => $delivery->id, 'consignment_id' => $consignment->id, 'provider' => 'carrybee', 'method' => 'rate_card',
            'rate_card_version' => 'v2026-10-11', 'to_band' => $district === 'Dhaka' ? 'dhaka' : 'outside_dhaka', 'service' => 'normal',
            'weight_grams' => $declared, 'cod_amount' => '0', 'delivery_fee' => (string) $extra['estimate'], 'cod_fee' => '0',
            'total' => (string) $extra['estimate'], 'confidence' => 'low', 'basis' => 'test',
        ]);
    }

    return $consignment;
}

it('seeds the approved Pathao and CarryBee cards from the couriers own prices, CarryBee as a list price with no invented discount', function () {
    $pathao = CourierRateCard::effectiveFor('pathao');
    $carrybee = CourierRateCard::effectiveFor('carrybee');

    expect($pathao)->version->toBe('v2026-10-11')->status->toBe('approved')->is_list_price->toBeFalse()
        ->and($carrybee)->version->toBe('v2026-10-11')->is_list_price->toBeTrue()
        ->and($carrybee->lines()->whereNotNull('expected_discount')->count())->toBe(0)
        // Nothing above 5 kg was sampled at CarryBee, so nothing is priced there.
        ->and($carrybee->lines()->whereNull('weight_to_g')->count())->toBe(0)
        ->and($pathao->lines()->where('service', 'same_day')->count())->toBe(2)
        ->and(CourierRateCard::effectiveFor('pathao', '2026-10-10'))->toBeNull();
});

it('prices from the card in force with the exact basis text, and a list price without a known discount is Low', function () {
    $account = cfeAccount($this, 'carrybee');

    $estimate = cfeEstimate($account, $this, 1200, 'Panchagarh', 'Panchagarh Sadar', 1700, info: ['items_weighed' => 2, 'items_total' => 2]);

    expect($estimate->method)->toBe('rate_card')
        ->and($estimate->deliveryFee)->toBe(150.0)
        ->and($estimate->codFee)->toBe(8.5)
        ->and($estimate->confidence)->toBe('low')
        ->and($estimate->toBand)->toBe('outside_dhaka')
        ->and($estimate->rateCardVersion)->toBe('v2026-10-11')
        ->and($estimate->basis)->toBe('Estimated charge Tk 158.50. Basis: weight 1.2 kg (2 of 2 items weighed), Dhaka -> Panchagarh (Outside Dhaka), CarryBee rate card v2026-10-11 (list price), COD 0.5% included. Confidence: Low');

    // With an expected discount filled in, the card is Medium and says so.
    $card = CourierRateCard::effectiveFor('carrybee');
    $card->forceFill(['status' => 'draft'])->save();
    $card->lines()->where('to_band', 'outside_dhaka')->update(['expected_discount' => 35]);
    $card->forceFill(['status' => 'approved'])->save();

    $discounted = cfeEstimate($account, $this, 1200, 'Panchagarh', 'Panchagarh Sadar');
    expect($discounted->deliveryFee)->toBe(115.0)->and($discounted->confidence)->toBe('medium')
        ->and($discounted->basis)->toContain('less expected discount Tk 35')->toContain('no COD');
});

it('adds each started kilogram above 2 kg, and prices same day on its own lines', function () {
    $account = cfeAccount($this, 'pathao');

    expect(cfeEstimate($account, $this, 500)->deliveryFee)->toBe(60.0)
        ->and(cfeEstimate($account, $this, 501)->deliveryFee)->toBe(70.0)
        ->and(cfeEstimate($account, $this, 2000)->deliveryFee)->toBe(90.0)
        // 3.2 kg: 90 + 2 started kilograms x 15.
        ->and(cfeEstimate($account, $this, 3200)->deliveryFee)->toBe(120.0)
        // Outside Dhaka: + 25 per kg.
        ->and(cfeEstimate($account, $this, 2100, 'Panchagarh', 'Panchagarh Sadar')->deliveryFee)->toBe(195.0)
        ->and(cfeEstimate($account, $this, 400, service: 'same_day')->deliveryFee)->toBe(120.0)
        ->and(cfeEstimate($account, $this, 2500, service: 'same_day')->deliveryFee)->toBe(165.0)
        // Same day outside Dhaka is not on the card, and there is no history.
        ->and(cfeEstimate($account, $this, 400, 'Panchagarh', 'Panchagarh Sadar', service: 'same_day')->method)->toBe('insufficient');

    $card = cfeEstimate($account, $this, 800, cod: 1000);
    expect($card->codFee)->toBe(10.0)->and($card->returnFeeScenario)->toBe(35.0)->and($card->total())->toBe(80.0);
});

it('asks Pathao for its live price first, and keeps it for six hours per zone and bracket', function () {
    $account = cfeAccount($this, 'pathao');
    $priced = 0;

    Http::fake(function (Request $request) use (&$priced) {
        if (str_ends_with($request->url(), '/issue-token')) {
            return Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'tok-ABC']);
        }

        $priced++;

        return Http::response(['type' => 'success', 'data' => [
            'price' => 70, 'discount' => 5, 'promo_discount' => 0, 'plan_id' => 9, 'cod_enabled' => 1,
            'cod_percentage' => 0.01, 'additional_charge' => 0, 'final_price' => 65,
        ]]);
    });

    $first = cfeEstimate($account, $this, 800, cod: 1700, quote: CourierFeeEstimator::QUOTE_LIVE);
    $again = cfeEstimate($account, $this, 950, cod: 1700, quote: CourierFeeEstimator::QUOTE_LIVE);

    expect($first->method)->toBe('provider_quote')->and($first->confidence)->toBe('high')
        ->and($first->deliveryFee)->toBe(65.0)->and($first->codFee)->toBe(17.0)
        ->and($first->basis)->toBe('Estimated charge Tk 82. Basis: weight 0.8 kg, Dhaka -> Dhaka (Inside Dhaka), Pathao live quote, COD 1% included. Confidence: High')
        ->and($again->deliveryFee)->toBe(65.0)
        ->and($priced)->toBe(1);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/merchant/price-plan')
        && $r['store_id'] === 393838 && $r['item_type'] === 2 && $r['delivery_type'] === 48
        && $r['item_weight'] === 0.8 && $r['recipient_city'] === 1 && $r['recipient_zone'] === 52);

    // Another bracket asks again; after six hours the same bracket asks again.
    cfeEstimate($account, $this, 1500, quote: CourierFeeEstimator::QUOTE_LIVE);
    expect($priced)->toBe(2);
    Carbon::setTestNow(now()->addHours(6)->addMinute());
    cfeEstimate($account, $this, 800, quote: CourierFeeEstimator::QUOTE_LIVE);
    expect($priced)->toBe(3);
});

it('falls back to the card when Pathao will not quote, and never calls Pathao at booking time', function () {
    $account = cfeAccount($this, 'pathao');
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);

    expect(cfeEstimate($account, $this, 800, quote: CourierFeeEstimator::QUOTE_LIVE)->method)->toBe('rate_card');

    Http::fake(fn () => throw new RuntimeException('no call expected'));
    expect(cfeEstimate($account, $this, 800, quote: CourierFeeEstimator::QUOTE_CACHED)->method)->toBe('rate_card');
});

it('says Insufficient data with the reason for a missing weight or an unmapped place, never a zero', function () {
    $account = cfeAccount($this, 'carrybee');

    $noWeight = cfeEstimate($account, $this, null, info: ['items_weighed' => 1, 'items_total' => 2]);
    expect($noWeight->method)->toBe('insufficient')->and($noWeight->deliveryFee)->toBeNull()->and($noWeight->total())->toBeNull()
        ->and($noWeight->confidence)->toBe('none')
        ->and($noWeight->basis)->toBe('Insufficient data. No parcel weight (1 of 2 items weighed). Weigh the parcel and type it.');

    $unmapped = cfeEstimate($account, $this, 800, 'Dhaka', 'Mirpur');
    expect($unmapped->method)->toBe('insufficient')->and($unmapped->reason)->toContain('Mirpur, Dhaka has no CarryBee zone yet');

    // 6 kg: above anything CarryBee's list was sampled at, and no history.
    $heavy = cfeEstimate($account, $this, 6000);
    expect($heavy->method)->toBe('insufficient')->and($heavy->reason)->toContain('0 clean past parcels (at least 10 are needed)');
});

it('applies the COD percent on the collectable amount, and a minimum only when the account has one', function () {
    $account = cfeAccount($this, 'carrybee');
    $estimator = app(CourierFeeEstimator::class);

    expect($estimator->codFee($account, 1598, $estimator->codPercent($account)))->toBe(7.99)
        ->and($estimator->codFee($account, 0, 0.5))->toBe(0.0);

    $withMinimum = cfeAccount($this, 'pathao', ['cod_min_fee' => 10]);
    expect($estimator->codPercent($withMinimum))->toBe(1.0)
        ->and($estimator->codFee($withMinimum, 300, 1.0))->toBe(10.0)
        ->and($estimator->codFee($withMinimum, 2000, 1.0))->toBe(20.0);
});

it('learns from at least 10 clean past parcels only, leaving out returned, cancelled, re-weighed and duplicated ones', function () {
    $account = cfeAccount($this, 'carrybee');
    CourierRateCard::effectiveFor('carrybee')->forceFill(['status' => 'retired'])->save();

    foreach (range(1, 9) as $n) {
        cfeSample($this, $n, 100 + $n);
    }

    // Bad samples: none of them counts.
    cfeSample($this, 20, 500, extra: ['status' => ConsignmentStatus::Returned]);
    cfeSample($this, 21, 500, extra: ['courier_status' => CourierStatus::Cancelled]);
    cfeSample($this, 22, 500, extra: ['hub' => 1500]);
    $dup = cfeSample($this, 23, 500);
    CourierStatementLine::create([
        'courier_statement_id' => CourierStatement::query()->value('id'), 'row_number' => 99, 'consignment_number' => 'FA23-again',
        'collectable_amount' => '0', 'collected_amount' => '0', 'delivery_fee' => '500', 'discount' => '0', 'net_fee' => '500',
        'cod_fee' => '0', 'additional_charge' => '0', 'final_fee' => '500', 'payout' => '0', 'consignment_id' => $dup->id,
    ]);

    $nine = cfeEstimate($account, $this, 1300, 'Panchagarh', 'Panchagarh Sadar');
    expect($nine->method)->toBe('insufficient')->and($nine->reason)->toContain('9 clean past parcels');

    cfeSample($this, 10, 110);
    $ten = cfeEstimate($account, $this, 1300, 'Panchagarh', 'Panchagarh Sadar');

    // Medians of 101..110: 105.5. Fewer than 30 samples: Low.
    expect($ten->method)->toBe('historical')->and($ten->deliveryFee)->toBe(105.5)->and($ten->sampleSize)->toBe(10)
        ->and($ten->confidence)->toBe('low')->and($ten->basis)->toContain('median of 10 past CarryBee parcels');

    $reasons = app(CourierFeeSamples::class)->rows()->pluck('excluded', 'consignment_number');
    expect($reasons['FA20'])->toBe('returned')->and($reasons['FA21'])->toBe('cancelled')
        ->and($reasons['FA22'])->toBe('reweighed')->and($reasons['FA23'])->toBe('duplicate')->and($reasons['FA1'])->toBeNull();
});

it('keeps the estimate when a booking succeeds, and the kept estimate can never be changed or deleted', function () {
    $account = cfeAccount($this, 'carrybee');
    $delivery = cfeChallan($this);
    Http::fake([CFE_CB.'/api/v2/orders' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['order' => [
        'consignment_id' => 'FA1110ABCD', 'delivery_fee' => '70', 'cod_fee' => 8.5,
    ]]], 201)]);

    $this->actingAs($this->manager)->post("/sales/deliveries/{$delivery->id}/courier-booking", [
        'courier_account_id' => $account->id, 'cod_amount' => '1700',
    ])->assertSessionHasNoErrors();

    $consignment = Consignment::query()->sole();
    $estimate = CourierFeeEstimate::query()->sole();

    // 2 x 600 g = 1.2 kg to Dhanmondi: CarryBee list 90 + 0.5% of 1,700.
    expect($estimate)->consignment_id->toBe($consignment->id)->delivery_id->toBe($delivery->id)
        ->method->toBe('rate_card')->rate_card_version->toBe('v2026-10-11')->to_band->toBe('dhaka')->from_band->toBe('dhaka')
        ->weight_grams->toBe(1200)->confidence->toBe('low')->created_by->toBe($this->manager->id)
        ->and((string) $estimate->delivery_fee)->toBe('90.00')->and((string) $estimate->cod_fee)->toBe('8.50')
        ->and((string) $estimate->total)->toBe('98.50')
        ->and($estimate->basis)->toBe('Estimated charge Tk 98.50. Basis: weight 1.2 kg (1 of 1 items weighed), Dhaka -> Dhaka (Dhaka), CarryBee rate card v2026-10-11 (list price), COD 0.5% included. Confidence: Low')
        // The courier's own quote stays a separate fact.
        ->and((string) $consignment->provider_fee_quoted)->toBe('70.0000');

    expect(fn () => $estimate->update(['total' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $estimate->delete())->toThrow(LogicException::class)
        ->and(CourierFeeEstimate::query()->sole()->total)->toBe('98.50');
});

it('keeps no estimate for a refused booking', function () {
    $account = cfeAccount($this, 'carrybee');
    $delivery = cfeChallan($this);
    Http::fake([CFE_CB.'/api/v2/orders' => Http::response(['error' => true, 'message' => 'Validation error'], 422)]);

    $this->actingAs($this->manager)->post("/sales/deliveries/{$delivery->id}/courier-booking", ['courier_account_id' => $account->id, 'cod_amount' => '1700']);

    expect(CourierFeeEstimate::query()->count())->toBe(0);
});

it('answers the booking form live, for whoever follows parcels, and only with accounts that serve the business', function () {
    $account = cfeAccount($this, 'carrybee');
    $delivery = cfeChallan($this);

    $this->actingAs($this->staff)->getJson("/sales/deliveries/{$delivery->id}/courier-estimate?courier_account_id={$account->id}&cod_amount=1700")
        ->assertOk()->assertJson(['method' => 'rate_card', 'total' => 98.5, 'confidence' => 'low', 'sufficient' => true]);

    $this->actingAs($this->staff)->getJson("/sales/deliveries/{$delivery->id}/courier-estimate?courier_account_id={$account->id}&weight_grams=2500")
        ->assertOk()->assertJsonPath('delivery_fee', 110)->assertJsonPath('basis', fn ($basis) => str_contains($basis, 'weight 2.5 kg (typed)'));

    $missing = cfeChallan($this, '1', product: $this->unweighed);
    $this->actingAs($this->staff)->getJson("/sales/deliveries/{$missing->id}/courier-estimate?courier_account_id={$account->id}")
        ->assertOk()->assertJson(['sufficient' => false, 'total' => null])
        ->assertJsonPath('reason', 'No parcel weight (0 of 1 items weighed). Weigh the parcel and type it.');

    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);
    $this->actingAs($stranger)->getJson("/sales/deliveries/{$delivery->id}/courier-estimate?courier_account_id={$account->id}")->assertForbidden();

    CourierAccountBusiness::query()->withoutGlobalScopes()->delete();
    $this->actingAs($this->staff)->getJson("/sales/deliveries/{$delivery->id}/courier-estimate?courier_account_id={$account->id}")->assertNotFound();
});

it('compares estimate, quote and statement on clean parcels only, with outliers flagged and the sample size shown', function () {
    cfeAccount($this, 'carrybee');
    cfeSample($this, 1, 100, extra: ['estimate' => 110, 'quote' => 100]);
    cfeSample($this, 2, 100, extra: ['estimate' => 90, 'quote' => 100]);
    cfeSample($this, 3, 100, extra: ['estimate' => 150, 'quote' => 105]);
    // Returned: shown, never counted.
    cfeSample($this, 4, 100, extra: ['estimate' => 500, 'status' => ConsignmentStatus::Returned]);

    $report = app(CourierFeeVariance::class)->report(['from' => '2026-10-01', 'to' => '2026-10-11']);

    expect($report['summary'])->parcels->toBe(4)->clean->toBe(3)
        ->and($report['summary']['estimate'])->toBe(['count' => 3, 'mean_abs' => 23.33, 'mean_abs_pct' => 23.3, 'median_diff' => 10.0, 'median_pct' => 10.0, 'outliers' => 1])
        ->and($report['summary']['quote'])->toMatchArray(['count' => 3, 'median_diff' => 0.0, 'outliers' => 0])
        ->and($report['summary']['excluded'])->toBe([['reason' => 'returned', 'label' => 'Returned', 'count' => 1]])
        ->and($report['by_band'][0])->toMatchArray(['band' => 'outside_dhaka', 'count' => 3, 'estimate_median_pct' => 10.0])
        ->and(collect($report['rows'])->firstWhere('consignment_number', 'FA3')['estimate_outlier'])->toBeTrue()
        ->and(app(CourierFeeVariance::class)->report(['provider' => 'pathao'])['summary']['parcels'])->toBe(0)
        ->and(app(CourierFeeVariance::class)->report(['bracket' => '0-500 g'])['summary']['parcels'])->toBe(0);

    $this->actingAs($this->manager)->get('/sales/courier-fees?from=2026-10-01&to=2026-10-11')->assertOk()
        ->assertInertia(fn ($page) => $page->component('sales/courier-fees/index')->where('report.summary.clean', 3));
    $this->actingAs($this->staff)->get('/sales/courier-fees')->assertForbidden();
});

it('proposes a calibrated draft only from 10 clean parcels, and the Owner approves it from a date', function () {
    cfeAccount($this, 'carrybee');

    foreach (range(1, 9) as $n) {
        cfeSample($this, $n, 90);
    }

    $this->actingAs($this->manager)->post('/settings/couriers/rate-cards/propose', ['provider' => 'carrybee'])
        ->assertSessionHasErrors(['provider' => 'No line of v2026-10-11 has 10 clean parcels yet. Nothing to propose.']);

    cfeSample($this, 10, 90);
    $this->actingAs($this->manager)->post('/settings/couriers/rate-cards/propose', ['provider' => 'carrybee'])->assertSessionHasNoErrors();

    $draft = CourierRateCard::query()->where('status', 'draft')->sole();
    $line = $draft->lines()->where('to_band', 'outside_dhaka')->where('weight_from_g', 1000)->sole();
    $untouched = $draft->lines()->where('to_band', 'dhaka')->where('weight_from_g', 1000)->sole();

    // List price kept; the difference becomes the expected discount.
    expect($draft)->source->toBe('calibration')->is_list_price->toBeTrue()->version->toBe('v2026-10-11-2')
        ->and((string) $line->price)->toBe('150.00')->and((string) $line->expected_discount)->toBe('60.00')
        ->and($line->sample_count)->toBe(10)->and((string) $line->previous_price)->toBe('150.00')
        ->and($untouched->expected_discount)->toBeNull()->and($untouched->sample_count)->toBe(0);

    // A Manager proposes; only the Owner approves, and never into the past.
    $this->actingAs($this->manager)->post("/settings/couriers/rate-cards/{$draft->id}/approve", ['effective_from' => '2026-10-12'])->assertForbidden();
    $this->actingAs($this->owner)->post("/settings/couriers/rate-cards/{$draft->id}/approve", ['effective_from' => '2026-10-10'])
        ->assertSessionHasErrors('effective_from');
    $this->actingAs($this->owner)->post("/settings/couriers/rate-cards/{$draft->id}/approve", ['effective_from' => '2026-10-12'])->assertSessionHasNoErrors();

    $account = CourierAccount::query()->sole();
    expect(cfeEstimate($account, $this, 1200, 'Panchagarh', 'Panchagarh Sadar')->rateCardVersion)->toBe('v2026-10-11')
        ->and(cfeEstimate($account, $this, 1200, 'Panchagarh', 'Panchagarh Sadar', on: '2026-10-12'))
        ->rateCardVersion->toBe('v2026-10-11-2')->deliveryFee->toBe(90.0)->confidence->toBe('medium');
});

it('never changes an approved card, edits only drafts, and reads and writes the CSV', function () {
    $approved = CourierRateCard::effectiveFor('pathao');

    expect(fn () => $approved->lines()->first()->update(['price' => 1]))->toThrow(LogicException::class);
    $this->actingAs($this->owner)->put("/settings/couriers/rate-cards/{$approved->id}", ['lines' => [['from_band' => 'inside_dhaka', 'to_band' => 'inside_dhaka', 'weight_from_g' => 0, 'price' => 1]]])
        ->assertSessionHasErrors('card');

    $this->actingAs($this->manager)->post('/settings/couriers/rate-cards', ['provider' => 'pathao', 'based_on_id' => $approved->id, 'version' => 'v-test'])
        ->assertSessionHasNoErrors();
    $draft = CourierRateCard::query()->where('version', 'v-test')->sole();
    expect($draft->lines()->count())->toBe($approved->lines()->count());

    $export = $this->actingAs($this->manager)->get("/settings/couriers/rate-cards/{$approved->id}/export");
    $export->assertOk();
    expect($export->streamedContent())->toStartWith("from_band,to_band,service,weight_from_g,weight_to_g,price,extra_per_kg_after_g,extra_per_kg_price,return_fee_percent,expected_discount\n");

    $csv = "from_band,to_band,service,weight_from_g,weight_to_g,price,extra_per_kg_after_g,extra_per_kg_price,return_fee_percent,expected_discount\n"
        ."inside_dhaka,inside_dhaka,normal,0,1000,65,,,50,\n"
        ."inside_dhaka,outside_dhaka,normal,0,,120,2000,25,50,10\n";
    $this->actingAs($this->manager)->post("/settings/couriers/rate-cards/{$draft->id}/import", [
        'file' => UploadedFile::fake()->createWithContent('card.csv', $csv),
    ])->assertSessionHasNoErrors();

    expect($draft->fresh()->source)->toBe('import')->and($draft->lines()->count())->toBe(2)
        ->and((string) $draft->lines()->where('to_band', 'outside_dhaka')->sole()->expected_discount)->toBe('10.00');

    // Overlapping brackets and unknown bands are refused with a sentence.
    $this->actingAs($this->manager)->put("/settings/couriers/rate-cards/{$draft->id}", ['lines' => [
        ['from_band' => 'inside_dhaka', 'to_band' => 'inside_dhaka', 'weight_from_g' => 0, 'weight_to_g' => 1000, 'price' => 60],
        ['from_band' => 'inside_dhaka', 'to_band' => 'inside_dhaka', 'weight_from_g' => 500, 'weight_to_g' => 2000, 'price' => 80],
    ]])->assertSessionHasErrors('lines');
    $this->actingAs($this->manager)->put("/settings/couriers/rate-cards/{$draft->id}", ['lines' => [
        ['from_band' => 'dhaka', 'to_band' => 'inside_dhaka', 'weight_from_g' => 0, 'price' => 60],
    ]])->assertSessionHasErrors('lines.0.from_band');

    $this->actingAs($this->manager)->delete("/settings/couriers/rate-cards/{$draft->id}")->assertSessionHasNoErrors();
    expect(CourierRateCard::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(CourierRateCardLine::query()->where('courier_rate_card_id', $approved->id)->count())->toBeGreaterThan(0);
});

it('shows the rate cards to Managers and Owners only, and the approve button to the Owner', function () {
    $this->actingAs($this->owner)->get('/settings/couriers/rate-cards?provider=carrybee')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/courier-rate-cards')->where('provider', 'carrybee')
            ->where('card.version', 'v2026-10-11')->where('card.in_force', true)->where('can_approve', true));
    $this->actingAs($this->manager)->get('/settings/couriers/rate-cards')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_approve', false));
    $this->actingAs($this->staff)->get('/settings/couriers/rate-cards')->assertForbidden();

    $card = CourierRateCard::effectiveFor('pathao');
    $this->actingAs($this->manager)->post("/settings/couriers/rate-cards/{$card->id}/retire")->assertForbidden();
    $this->actingAs($this->owner)->post("/settings/couriers/rate-cards/{$card->id}/retire")->assertSessionHasNoErrors();
    expect(CourierRateCard::effectiveFor('pathao'))->toBeNull();
});

it('grants the rate card permissions to the roles that already exist, by migration', function () {
    $migration = require base_path('app/Modules/Sales/database/migrations/2026_10_11_600001_courier_rate_card_permissions_are_granted_to_existing_roles.php');
    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($this->owner->fresh()->can('couriers.ratecards.approve'))->toBeFalse();

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    expect($this->owner->fresh()->can('couriers.ratecards.approve'))->toBeTrue()
        ->and($this->owner->fresh()->can('couriers.ratecards.propose'))->toBeTrue()
        ->and($this->manager->fresh()->can('couriers.ratecards.propose'))->toBeTrue()
        ->and($this->manager->fresh()->can('couriers.ratecards.approve'))->toBeFalse()
        ->and($this->staff->fresh()->can('couriers.ratecards.propose'))->toBeFalse();
});

it('compares band codes, providers and versions as text in every query (strict MySQL)', function () {
    $source = collect([
        'app/Modules/Sales/Couriers/Models/CourierRateCard.php',
        'app/Modules/Sales/Couriers/Fees/CourierFeeEstimator.php',
        'app/Modules/Sales/Couriers/Fees/RateCardBook.php',
    ])->map(fn ($f) => file_get_contents(base_path($f)))->implode("\n");

    expect($source)->toContain("->where('provider', (string) \$provider)")
        ->toContain("->where('from_band', (string) \$fromBand)")
        ->toContain("->where('to_band', (string) \$toBand)")
        ->toContain("->where('version', (string) \$label)");
});

// ⚠ Bug hunt 16 Oct 2026 (03 K4): an account linked once per brand, with no
// "any brand" link, has no single link for the business, so the estimate was a
// 404 and the live Pathao quote was never asked.
it('estimates through the link for the order brand when an account is linked per brand', function () {
    $account = cfeAccount($this, 'carrybee');
    CourierAccountBusiness::query()->withoutGlobalScopes()->where('courier_account_id', $account->id)->delete();
    $laladia = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $bionic = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);
    foreach ([$laladia, $bionic] as $i => $brand) {
        CourierAccountBusiness::query()->withoutGlobalScopes()->create([
            'courier_account_id' => $account->id, 'business_id' => $this->business->id, 'brand_id' => $brand->id,
            'provider_store_id' => (string) (24869 + $i), 'provider_store_name' => $brand->name, 'money_account_id' => $this->money->id, 'is_default' => true,
        ]);
    }
    $delivery = cfeChallan($this);
    DB::table('sales_orders')->update(['brand_id' => $laladia->id]);

    $this->actingAs($this->staff)->getJson("/sales/deliveries/{$delivery->id}/courier-estimate?courier_account_id={$account->id}&cod_amount=1700")
        ->assertOk()->assertJson(['method' => 'rate_card']);
});
