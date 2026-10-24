<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\Sellers\MoveSellerStock;
use App\Modules\Sales\Actions\Sellers\OpenSellerStock;
use App\Modules\Sales\Actions\Sellers\ReportSellerSales;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Corrections\SellerTransferActionPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SellerSale;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Support\SellerStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesWorld;

/**
 * ⭐ Initial plan B21 (R109, R106; Joy's Q3 = A, 24 Oct 2026): retail sellers
 * on consignment. Rakib takes honey from Savar into his seller stock (a
 * transfer at cost, a sheet at Trade Price), reports what he sold (counter
 * sales from his stock: revenue and COGS as usual, the money into his
 * Employee cash, owed by him, or owed by a named customer), returns the rest
 * (a transfer back). Corrections through the engine. Nobody else may sell or
 * move his stock.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $w = $this->s->w;
    $this->business = $w->business;
    (new WalkInCustomerSeeder)->run();
    $w->enter($this->business);

    // The Trade Price group by its CODE (SHOP today, TP after Joy's Q13).
    $this->tp = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'SHOP', 'name' => 'Retail shop', 'falls_back_to_mrp' => false, 'is_active' => true]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->s->honey->id, 'price_group_id' => $this->tp->id, 'unit_price' => '700', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->s->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    $this->s->customer->update(['credit_limit' => '100000']);

    $this->rakibLogin = $w->member('Sales', 'Rakib');
    $this->rakib = Employee::create([
        'business_id' => $this->business->id, 'user_id' => $this->rakibLogin->id,
        'employee_code' => 'BDG-000040', 'first_name' => 'Rakib', 'last_name' => 'Hasan',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $this->nazmul = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000041', 'first_name' => 'Nazmul', 'last_name' => 'Islam',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $this->petty = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty Cash - Rakib',
        'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $this->rakib->id,
    ]);
    MoneyLedgers::ensureOwnLedger($this->petty);
    $this->petty->refresh();

    $this->stock = app(OpenSellerStock::class)->handle($this->rakib);

    $this->handOver = fn (string $qty = '10', string $on = '2026-10-15') => app(MoveSellerStock::class)->handle(
        SellerTransfer::KIND_HANDOVER, $this->rakib, $this->s->warehouse, [['product' => $this->s->honey, 'quantity' => $qty]], $w->manager, $on,
    );
    $this->giveBack = fn (string $qty = '3', string $on = '2026-10-16') => app(MoveSellerStock::class)->handle(
        SellerTransfer::KIND_RETURN, $this->rakib, $this->s->warehouse, [['product' => $this->s->honey, 'quantity' => $qty]], $w->manager, $on, 'Could not sell',
    );
    $this->sell = fn (string $qty = '4', string $payment = SellerSale::PAY_COLLECTED, ?Customer $customer = null, string $basis = SellerSale::BASIS_TRADE, string $on = '2026-10-16') => app(ReportSellerSales::class)->handle(
        $this->rakib, $on, [['product' => $this->s->honey, 'quantity' => $qty]], $payment, $w->manager,
        customer: $customer, priceBasis: $basis, moneyAccount: $payment === SellerSale::PAY_COLLECTED ? $this->petty : null, soldTo: $customer === null ? 'Mitali Store' : null,
    );
    $this->held = fn () => (float) StockBatch::query()->where('warehouse_id', $this->stock->id)->sum('current_quantity');
    $this->savar = fn () => (float) StockBatch::query()->where('warehouse_id', $this->s->warehouse->id)->sum('current_quantity');
    $this->statement = fn () => app(SellerStatement::class)->for($this->rakib, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('makes an employee a seller with his own seller stock, once', function () {
    expect($this->stock->isSellerStock())->toBeTrue()
        ->and($this->stock->name)->toBe('Seller stock - Rakib Hasan')
        ->and($this->stock->code)->toBe('SELLER-BDG-000040')
        ->and($this->stock->is_default)->toBeFalse()
        ->and(app(OpenSellerStock::class)->handle($this->rakib)->id)->toBe($this->stock->id);

    ($this->as)($this->s->w->owner)->post('/sales/sellers', ['employee_id' => $this->nazmul->id])
        ->assertRedirect("/sales/sellers/{$this->nazmul->id}");
    expect(Warehouse::query()->where('seller_employee_id', $this->nazmul->id)->exists())->toBeTrue();
});

it('hands goods over at cost, with the Trade Price on the sheet, and posts no journal', function () {
    $journals = Journal::query()->count();
    $sheet = ($this->handOver)('10');

    expect($sheet->number)->toBe('SH-2026-27-0001')
        ->and((float) $sheet->trade_value)->toBe(7000.0)
        ->and((float) $sheet->cost_value)->toBe(5000.0)
        ->and((float) $sheet->lines->first()->trade_price)->toBe(700.0)
        ->and(($this->held)())->toBe(10.0)
        ->and(($this->savar)())->toBe(90.0)
        ->and(Journal::query()->count())->toBe($journals)
        ->and(StockBatch::query()->where('warehouse_id', $this->stock->id)->first()->unit_cost)->toEqual('500.0000');

    ($this->as)($this->s->w->manager)->get("/sales/sellers/sheets/{$sheet->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/sellers/sheet')->where('sheet.total', '7,000.00')->where('sheet.seller', 'Rakib Hasan'));
});

it('refuses a hand-over of an item with no Trade Price', function () {
    ProductPrice::query()->where('price_group_id', $this->tp->id)->delete();

    expect(fn () => ($this->handOver)('2'))->toThrow(ValidationException::class);
    expect(($this->held)())->toBe(0.0);
});

it('reported sales collected into his Employee cash post revenue and COGS from his seller stock', function () {
    ($this->handOver)('10');
    $sale = ($this->sell)('4');

    expect($sale->number)->toBe('SS-2026-27-0001')
        ->and((float) $sale->total)->toBe(2800.0)
        ->and($sale->sold_to)->toBe('Mitali Store')
        ->and($sale->invoice->customer->isWalkIn())->toBeTrue()
        ->and(($this->held)())->toBe(6.0)
        ->and($this->s->balance('cost_of_goods_sold'))->toBe(200000)
        ->and($this->s->w->balance(Account::find($this->petty->account_id)))->toBe(280000)
        ->and($this->s->reconciliationProblems())->toBe([]);

    $st = ($this->statement)();
    expect($st['taken'])->toBe(['quantity' => '10', 'trade' => '7,000.00', 'cost' => '5,000.00'])
        ->and($st['sold'])->toBe(['quantity' => '4', 'value' => '2,800.00', 'cost' => '2,000.00', 'margin' => '800.00'])
        ->and($st['holding']['quantity'])->toBe('6')
        ->and($st['holding']['trade'])->toBe('4,200.00')
        ->and($st['money']['collected'])->toBe('2,800.00')
        ->and($st['money']['cash_held'])->toBe('2,800.00')
        ->and($st['ageing'][0]['days'])->toBe(2);
});

it('a sale he answers for stays open on his own staff account; a named credit customer at his own price owes it', function () {
    ($this->handOver)('10');
    $owes = ($this->sell)('1', SellerSale::PAY_SELLER_OWES);
    $credit = ($this->sell)('2', SellerSale::PAY_ON_ACCOUNT, $this->s->customer, SellerSale::BASIS_CUSTOMER);

    expect($owes->invoice->customer->isStaff())->toBeTrue()
        ->and($owes->invoice->customer->employee_id)->toBe($this->rakib->id)
        ->and($owes->invoice->outstanding()->toTaka())->toBe('700.00')
        ->and($credit->invoice->customer_id)->toBe($this->s->customer->id)
        ->and((float) $credit->total)->toBe(1700.0)
        ->and($this->s->reconciliationProblems())->toBe([]);

    $st = ($this->statement)();
    expect($st['money']['seller_owes'])->toBe('700.00')
        ->and($st['money']['customers_owe'])->toBe('1,700.00')
        ->and($st['money']['collected'])->toBe('0.00');
});

it('on account needs a named customer, and a walk-in sale needs the money', function () {
    ($this->handOver)('5');

    expect(fn () => ($this->sell)('1', SellerSale::PAY_ON_ACCOUNT))->toThrow(ValidationException::class);
    expect(SellerSale::query()->count())->toBe(0);
});

it('takes unsold goods back by transfer; the statement shows who sold how much', function () {
    ($this->handOver)('10');
    ($this->sell)('4');
    $back = ($this->giveBack)('3');

    expect($back->number)->toBe('SR-2026-27-0001')
        ->and($back->reason)->toBe('Could not sell')
        ->and(($this->held)())->toBe(3.0)
        ->and(($this->savar)())->toBe(93.0);

    $st = ($this->statement)();
    expect($st['returned']['quantity'])->toBe('3')
        ->and($st['products'][0])->toMatchArray(['taken' => '10', 'sold' => '4', 'returned' => '3', 'holding' => '3']);

    $all = app(SellerStatement::class)->all(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($all)->toHaveCount(1)->and($all[0]['sold']['value'])->toBe('2,800.00');
});

it('nobody else can sell or move his stock: counter, challan and warehouse transfer are refused', function () {
    ($this->handOver)('5');

    expect(fn () => app(RecordCounterSale::class)->handle(
        customer: $this->s->customer, warehouse: $this->stock, lines: [['product' => $this->s->honey, 'quantity' => '1', 'unitPrice' => '850']],
        createdBy: $this->s->w->owner, moneyAccount: $this->s->w->bank,
    ))->toThrow(ValidationException::class);

    $reason = Reason::query()->firstOrCreate(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Other']);
    expect(fn () => app(DispatchStockTransfer::class)->handle($this->s->honey, $this->stock, $this->s->warehouse, '1', $reason, $this->s->w->owner))
        ->toThrow(ValidationException::class);

    expect(($this->held)())->toBe(5.0)
        ->and(Warehouse::query()->operational()->pluck('id')->all())->not->toContain($this->stock->id);
});

it('reverses a hand-over through the engine while nothing of it was sold; refuses once sold', function () {
    $sheet = ($this->handOver)('10');
    $policy = app(SellerTransferActionPolicy::class);

    $result = $this->s->submit($this->s->input(SellerTransferActionPolicy::TYPE, $sheet, DocumentAction::Reverse), $this->s->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($sheet->fresh()->isReversed())->toBeTrue()
        ->and(($this->held)())->toBe(0.0)
        ->and(($this->savar)())->toBe(100.0)
        ->and(StockMovement::query()->whereNotNull('reverses_movement_id')->count())->toBe(2);

    $second = ($this->handOver)('5', '2026-10-16');
    ($this->sell)('1');
    $availability = $policy->availability($second->fresh(), $this->s->w->manager, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('goods_moved_on')
        ->and(($this->statement)()['taken']['quantity'])->toBe('5');
});

it('reverses a return: the goods go back into his seller stock', function () {
    ($this->handOver)('10');
    $back = ($this->giveBack)('4');

    $this->s->submit($this->s->input(SellerTransferActionPolicy::TYPE, $back, DocumentAction::Reverse), $this->s->w->owner);

    expect($back->fresh()->isReversed())->toBeTrue()
        ->and(($this->held)())->toBe(10.0)
        ->and(($this->savar)())->toBe(90.0)
        ->and(($this->statement)()['returned']['quantity'])->toBe('0');
});

it('a wrong report is voided through the invoice engine: goods back in his stock, money off his cash', function () {
    ($this->handOver)('10');
    $sale = ($this->sell)('4');

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $sale->invoice, DocumentAction::Reverse), $this->s->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(($this->held)())->toBe(10.0)
        ->and($this->s->balance('cost_of_goods_sold'))->toBe(0)
        ->and($this->s->reconciliationProblems())->toBe([]);

    $st = ($this->statement)();
    expect($st['sold']['quantity'])->toBe('0')
        ->and($st['money']['collected'])->toBe('0.00')
        ->and($st['sales'][0]['voided'])->toBeTrue();
});

it('a closed month: only the Owner may reverse a sheet dated in it', function () {
    $sheet = ($this->handOver)('2', '2026-10-05');
    $this->s->w->closeThrough('2026-10-10');
    $policy = app(SellerTransferActionPolicy::class);

    $manager = $policy->availability($sheet, $this->s->w->manager, DocumentAction::Reverse);
    $owner = $policy->availability($sheet, $this->s->w->owner, DocumentAction::Reverse);

    expect($manager->allowed && $manager->approval->value === 'none')->toBeFalse()
        ->and($owner->allowed)->toBeTrue();
});

it('screens: Owner and Manager manage; the seller reads only his own statement; others are refused', function () {
    ($this->handOver)('3');

    ($this->as)($this->s->w->manager)->get('/sales/sellers')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/sellers/index')->has('sellers', 1)->where('sellers.0.name', 'Rakib Hasan'));

    ($this->as)($this->s->w->manager)->get("/sales/sellers/{$this->rakib->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/sellers/show')->where('canManage', true)->has('forms.products')->has('statement.sheets.0.actions'));

    // Rakib himself: his own statement, read only, no forms.
    ($this->as)($this->rakibLogin)->get('/sales/sellers')->assertRedirect("/sales/sellers/{$this->rakib->id}");
    ($this->as)($this->rakibLogin)->get("/sales/sellers/{$this->rakib->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('forms', null));
    ($this->as)($this->rakibLogin)->post("/sales/sellers/{$this->rakib->id}/handover", [])->assertForbidden();

    app(OpenSellerStock::class)->handle($this->nazmul);
    ($this->as)($this->rakibLogin)->get("/sales/sellers/{$this->nazmul->id}")->assertForbidden();
    ($this->as)($this->s->cashier)->get('/sales/sellers')->assertForbidden();
});

it('the three popups post through HTTP, and a double click records once', function () {
    $as = ($this->as)($this->s->w->manager);
    $token = (string) Str::uuid();
    $body = ['submission' => $token, 'moved_on' => '2026-10-16', 'warehouse_id' => $this->s->warehouse->id, 'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '6']]];

    $as->post("/sales/sellers/{$this->rakib->id}/handover", $body)->assertSessionHasNoErrors();
    $as->post("/sales/sellers/{$this->rakib->id}/handover", $body)->assertSessionHasErrors('lines');
    expect(SellerTransfer::query()->count())->toBe(1)->and(($this->held)())->toBe(6.0);

    $as->post("/sales/sellers/{$this->rakib->id}/sales", [
        'submission' => (string) Str::uuid(), 'sold_on' => '2026-10-17', 'payment' => 'collected', 'price_basis' => 'trade',
        'money_account_id' => $this->petty->id, 'sold_to' => 'Walk-in at the haat', 'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $as->post("/sales/sellers/{$this->rakib->id}/return", [
        'submission' => (string) Str::uuid(), 'moved_on' => '2026-10-17', 'warehouse_id' => $this->s->warehouse->id, 'reason' => 'Unsold',
        'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '1']],
    ])->assertSessionHasNoErrors();

    expect(($this->held)())->toBe(3.0)->and(SellerSale::query()->count())->toBe(1);

    // A future date is refused.
    $as->post("/sales/sellers/{$this->rakib->id}/handover", ['submission' => (string) Str::uuid(), 'moved_on' => '2026-10-20'] + $body)->assertSessionHasErrors('moved_on');
});
