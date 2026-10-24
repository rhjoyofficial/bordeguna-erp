<?php

use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderCharge;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Initial plan B1 (25 Oct 2026): the purchase screen carries Joy's
 * sections 1 to 6: the purchasing employee (R40), a one-off payee on a cost
 * (R49), the supplier's later charges (R54), advances allocated on the one
 * screen (R56) and files on receipts and bills (R58). The quantity and weight
 * row (R51) is display only and is checked in the browser.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->supplier = $this->d->supplier;

    $this->sifat = Employee::create([
        'employee_code' => 'BDG-000071', 'first_name' => 'Sifat', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $this->as = fn ($user = null) => $this->actingAs($user ?? $this->w->owner)->withSession(['current_business_id' => $this->w->business->id]);

    $this->order = fn (array $overrides = []) => $overrides + [
        'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id, 'order_date' => '2026-10-17',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate']],
    ];

    $this->buy = fn (array $overrides = []) => app(RecordQuickPurchase::class)->handle(...($overrides + [
        'requestKey' => (string) Str::uuid(),
        'supplier' => $this->supplier,
        'warehouse' => $this->d->warehouse,
        'purchasedOn' => '2026-10-17',
        'lines' => [['product' => $this->d->salt, 'quantity' => '500', 'unit_cost' => '20.00']],
        'billNumber' => 'CM-501',
        'by' => $this->w->owner,
    ]));

    $this->advance = fn (string $amount) => app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-15', amount: $amount, createdBy: $this->w->owner,
    );

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

afterEach(fn () => Carbon::setTestNow());

// ── R40: the purchasing employee ─────────────────────────────────────────────

it('names the HR employee who handled the purchase; the old buyer login stays', function () {
    ($this->as)()->get('/purchasing/purchase-orders/new')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('employees', fn ($list) => collect($list)->contains(fn ($e) => $e['id'] === $this->sifat->id && str_contains($e['name'], 'Sifat Hossain'))));

    ($this->as)()->post('/purchasing/purchase-orders', ($this->order)(['purchasing_employee_id' => $this->sifat->id]))->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();

    expect((int) $order->purchasing_employee_id)->toBe($this->sifat->id)
        ->and((int) $order->buyer_id)->toBe($this->w->owner->id);

    ($this->as)()->get("/purchasing/purchase-orders/{$order->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('full.purchasing_employee', 'Sifat Hossain')->where('full.buyer', $this->w->owner->name));
});

it('refuses an employee that does not exist', function () {
    ($this->as)()->post('/purchasing/purchase-orders', ($this->order)(['purchasing_employee_id' => 999999]))
        ->assertSessionHasErrors('purchasing_employee_id');

    expect(PurchaseOrder::count())->toBe(0);
});

it('Buy and receive names the purchasing employee too', function () {
    ($this->as)()->post('/purchasing/buy-and-receive', [
        'request_key' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-17', 'bill_number' => 'CM-12', 'purchasing_employee_id' => $this->sifat->id,
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '10', 'unit_cost' => '20', 'entry_mode' => 'rate']],
    ])->assertSessionHasNoErrors();

    expect((int) QuickPurchase::query()->sole()->purchaseOrder->purchasing_employee_id)->toBe($this->sifat->id);
});

// ── R49: a one-off payee on a cost ───────────────────────────────────────────

it('keeps a one-off payee name on a cost paid from cash, through to the landed cost line', function () {
    ($this->as)()->post('/purchasing/purchase-orders', ($this->order)([
        'receive_now' => true,
        'charges' => [['cost_type' => 'freight', 'amount' => '300', 'allocation_method' => 'value', 'payee' => 'money', 'money_account_id' => $this->w->cash->id, 'payee_name' => 'Truck owner Karim']],
    ]))->assertSessionHasNoErrors();

    expect(PurchaseOrderCharge::query()->sole()->payee_name)->toBe('Truck owner Karim')
        ->and(LandedCostLine::query()->sole()->payee_name)->toBe('Truck owner Karim');

    ($this->as)()->get('/purchasing/purchase-orders/'.PurchaseOrder::query()->sole()->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('full.charges.0.payee_name', 'Truck owner Karim'));

    ($this->booksTie)();
});

it('drops a payee name on a cost owed to a supplier (the supplier is the payee)', function () {
    ($this->as)()->post('/purchasing/purchase-orders', ($this->order)([
        'charges' => [['cost_type' => 'freight', 'amount' => '300', 'allocation_method' => 'value', 'payee' => 'this', 'payee_name' => 'Somebody']],
    ]))->assertSessionHasNoErrors();

    expect(PurchaseOrderCharge::query()->sole()->payee_name)->toBeNull();
});

it('Buy and receive keeps a one-off payee name on an extra cost', function () {
    ($this->buy)(['extraCosts' => [['cost_type' => 'labour', 'amount' => '150', 'payee' => 'money', 'money_account_id' => $this->w->cash->id, 'payee_name' => 'Labour sardar Rahim']]]);

    expect(LandedCostLine::query()->sole()->payee_name)->toBe('Labour sardar Rahim');
});

// ── R54: the supplier's later charges ────────────────────────────────────────

it("adds a supplier's later charge on the order's delivery: a landed cost owed to the supplier, billed when posted", function () {
    $order = $this->d->order([[$this->d->salt, '100', '20.00']]);
    $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();

    ($this->as)()->post("/purchasing/purchase-orders/{$order->id}/later-charges", [
        'incurred_on' => '2026-10-16', 'goods_receipt_id' => $receipt->id, 'cost_type' => 'freight', 'amount' => '250', 'description' => 'Carriage claimed a week later',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $landed = LandedCost::query()->sole();
    $line = $landed->lines()->sole();

    expect($landed->goods_receipt_id)->toBe($receipt->id)
        ->and($landed->status)->toBe(LandedCostStatus::Posted)
        ->and((int) $line->supplier_id)->toBe($this->supplier->id)
        ->and($line->amount_paisa)->toBe(25000)
        ->and(SupplierBill::query()->where('landed_cost_id', $landed->id)->sole()->outstanding()->minor)->toBe(25000);

    ($this->as)()->get("/purchasing/purchase-orders/{$order->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('later_charge_types', fn ($types) => collect($types)->pluck('value')->contains('freight')));

    ($this->booksTie)();
});

it("refuses a later charge on another order's delivery, before the goods arrived, or without the right", function () {
    $order = $this->d->order([[$this->d->salt, '100', '20.00']]);
    $other = $this->d->order([[$this->d->sugar, '10', '10.00']]);
    $otherReceipt = GoodsReceipt::query()->where('purchase_order_id', $other->id)->sole();
    $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();

    ($this->as)()->post("/purchasing/purchase-orders/{$order->id}/later-charges", [
        'incurred_on' => '2026-10-16', 'goods_receipt_id' => $otherReceipt->id, 'cost_type' => 'freight', 'amount' => '250',
    ])->assertSessionHasErrors('goods_receipt_id');

    ($this->as)()->post("/purchasing/purchase-orders/{$order->id}/later-charges", [
        'incurred_on' => '2026-10-01', 'goods_receipt_id' => $receipt->id, 'cost_type' => 'freight', 'amount' => '250',
    ])->assertSessionHasErrors('incurred_on');

    ($this->as)($this->w->sales)->post("/purchasing/purchase-orders/{$order->id}/later-charges", [
        'incurred_on' => '2026-10-16', 'goods_receipt_id' => $receipt->id, 'cost_type' => 'freight', 'amount' => '250',
    ])->assertForbidden();

    expect(LandedCost::count())->toBe(0);
});

// ── R56: advances allocated on the one screen ────────────────────────────────

it('Buy and receive takes an earlier advance off the bill, then pays what is left', function () {
    $advance = ($this->advance)('3000');

    ($this->as)()->getJson("/purchasing/suppliers/{$this->supplier->id}/open-advances")->assertOk()
        ->assertJsonFragment(['key' => "advance:{$advance->id}", 'open' => '3000.00']);

    $quick = ($this->buy)([
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => '3000']],
        'payment' => ['money_account' => $this->w->bank, 'amount' => '7000'],
    ]);

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and(Money::ofTaka((string) SupplierAdvanceApplication::query()->sole()->amount)->minor)->toBe(300000)
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(Money::ofTaka((string) SupplierPayment::query()->sole()->amount)->minor)->toBe(700000);

    ($this->booksTie)();
});

it('an advance larger than the bill is refused and nothing is saved', function () {
    $advance = ($this->advance)('20000');
    $orders = PurchaseOrder::count();

    expect(fn () => ($this->buy)(['advances' => [['key' => "advance:{$advance->id}", 'amount' => '20000']]]))
        ->toThrow(ValidationException::class);

    expect(PurchaseOrder::count())->toBe($orders)
        ->and(SupplierAdvanceApplication::count())->toBe(0)
        ->and(QuickPurchase::count())->toBe(0);
});

it('the full purchase page allocates an advance when the bill is recorded now', function () {
    $advance = ($this->advance)('500');

    ($this->as)()->post('/purchasing/purchase-orders', ($this->order)([
        'receive_now' => true, 'bill_now' => true, 'request_key' => (string) Str::uuid(), 'bill_number' => 'CM-77',
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => '500']],
    ]))->assertSessionHasNoErrors();

    $quick = QuickPurchase::query()->sole();

    expect($quick->bill->outstanding()->minor)->toBe(200000 - 50000)
        ->and(SupplierAdvanceApplication::count())->toBe(1);

    ($this->booksTie)();
});

it('a held bill applies its advances when approved', function () {
    config(['accounting.purchasing_approvals' => true]);
    $advance = ($this->advance)('1000');

    ($this->buy)([
        'lines' => [['product' => $this->d->salt, 'quantity' => '10050', 'unit_cost' => '20.00']],
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => '1000']],
    ]);
    $approval = PurchaseApproval::query()->sole();

    expect(SupplierAdvanceApplication::count())->toBe(0)
        ->and($approval->payload['follow_ups']['advances'][0]['amount'])->toBe('1000');

    $bill = app(PurchasingApprovalGate::class)->approve($approval, $this->w->manager);

    expect(SupplierAdvanceApplication::count())->toBe(1)
        ->and($bill->fresh()->outstanding()->minor)->toBe(20_100_000 - 100_000);
});

it('applying an advance needs the advance right on the one screen too', function () {
    $advance = ($this->advance)('500');
    $this->w->sales->givePermissionTo(['purchasing.quick.purchase', 'purchasing.po.create', 'purchasing.receipt.record', 'purchasing.bill.record']);

    expect(fn () => ($this->buy)(['by' => $this->w->sales, 'advances' => [['key' => "advance:{$advance->id}", 'amount' => '500']]]))
        ->toThrow(AuthorizationException::class, 'apply an advance');
});

// ── R58: files on receipts and bills ─────────────────────────────────────────

it('keeps files on a goods receipt and a supplier bill, lists them, and removes one', function () {
    Storage::fake('local');
    Storage::fake(config('filesystems.default'));
    $order = $this->d->order([[$this->d->salt, '100', '20.00']]);
    $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();
    $bill = $this->d->bill($order);

    ($this->as)()->post("/purchasing/receipts/{$receipt->id}/files", ['file' => UploadedFile::fake()->image('challan.jpg')])->assertSessionHasNoErrors();
    ($this->as)()->post("/purchasing/bills/{$bill->id}/files", ['file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();

    $file = Attachment::query()->where('attachable_type', $receipt->getMorphClass())->sole();

    expect($file->collection)->toBe(GoodsReceipt::ATTACHMENTS_COLLECTION)
        ->and(Attachment::query()->where('attachable_type', $bill->getMorphClass())->sole()->collection)->toBe(SupplierBill::ATTACHMENTS_COLLECTION);

    ($this->as)()->get("/purchasing/receipts/{$receipt->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('files.0.name', 'challan.jpg')->where('can_attach', true));
    ($this->as)()->get("/purchasing/bills/{$bill->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('files.0.name', 'memo.pdf'));

    // The receipt's file through the bill's route is not found.
    ($this->as)()->delete("/purchasing/bills/{$bill->id}/files/{$file->id}")->assertNotFound();
    ($this->as)()->delete("/purchasing/receipts/{$receipt->id}/files/{$file->id}")->assertSessionHasNoErrors();

    expect(Attachment::query()->where('attachable_type', $receipt->getMorphClass())->count())->toBe(0);
});

it('refuses a file without the right to record the document', function () {
    Storage::fake(config('filesystems.default'));
    $order = $this->d->order([[$this->d->salt, '100', '20.00']]);
    $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();

    ($this->as)($this->w->sales)->post("/purchasing/receipts/{$receipt->id}/files", ['file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();

    expect(Attachment::count())->toBe(0);
});
