<?php

use App\Models\User;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyPosition;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\TakeOffWhatTheyOwe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DiscountWorld;
use Tests\Support\PartyWorld;

/**
 * ⭐ A purchase taken off EVERYTHING the firm owes us (Joy, 26 Oct 2026: "from
 * total 57,331 not 17,400"): our advance first, then their open invoices by
 * set-off, one amount, all or nothing.
 *
 * The Ayesha case: she owes us 39,931 on an invoice and holds 17,400 of our
 * advance. We buy 20,000 from her: 17,400 comes off the advance and 2,600 off
 * the invoice, the bill is settled, and the net drops by exactly 20,000.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->p = PartyWorld::make($this->w);
    $this->supplier = $this->p->supplier;

    $this->invoice = $this->p->invoice('39931', '2026-10-01');
    $this->advance = app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-05', amount: '17400', createdBy: $this->w->owner,
    );

    $this->buy = fn (array $overrides = []) => app(RecordQuickPurchase::class)->handle(...($overrides + [
        'requestKey' => (string) Str::uuid(),
        'supplier' => $this->supplier,
        'warehouse' => $this->d->warehouse,
        'purchasedOn' => '2026-10-17',
        'lines' => [['product' => $this->d->salt, 'quantity' => '1000', 'unit_cost' => '20.00']],
        'billNumber' => 'AYESHA-211026',
        'by' => $this->w->owner,
    ]));

    $this->net = fn () => PartyPosition::of($this->supplier->fresh(), $this->p->customer->fresh())->net()->minor;

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

afterEach(fn () => Carbon::setTestNow());

it('shows our advance and their open invoices, 57,331 in all, matching the both-sides net', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->getJson("/purchasing/suppliers/{$this->supplier->id}/what-they-owe")->assertOk()
        ->assertJsonPath('linked', true)
        ->assertJsonPath('advance_total', '17400.00')
        ->assertJsonPath('invoice_total', '39931.00')
        ->assertJsonPath('total', '57331.00')
        ->assertJsonPath('invoices.0.number', $this->invoice->invoice_number)
        ->assertJsonPath('posts_now', true);

    expect(($this->net)())->toBe(5_733_100);
});

it('takes a 20,000 purchase off the advance first, then the invoice by a set-off that posts at once', function () {
    $before = ($this->net)();

    $quick = ($this->buy)(['takeOff' => '20000']);

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(Money::ofTaka((string) SupplierAdvanceApplication::query()->sole()->amount)->minor)->toBe(1_740_000);

    $setOff = SetOff::query()->sole();

    expect($setOff->status)->toBe(SetOff::POSTED)
        ->and($setOff->money()->minor)->toBe(260_000)
        ->and($setOff->reason)->toBe('Purchase AYESHA-211026 taken off what Rahman Traders owes us')
        ->and($this->invoice->fresh()->outstanding()->minor)->toBe(3_733_100)
        ->and($before - ($this->net)())->toBe(2_000_000);

    $takenOff = TakeOffWhatTheyOwe::takenOff($quick->bill);

    expect(TakeOffWhatTheyOwe::sentence($takenOff))
        ->toBe("17,400.00 from our advance; 2,600.00 off {$this->invoice->invoice_number} by set-off {$setOff->number}.");

    // The result page shows both.
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->get("/purchasing/buy-and-receive/{$quick->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('taken_off.0.kind', 'advance')->where('taken_off.0.amount', '17400.00')
            ->where('taken_off.1.kind', 'set_off')->where('taken_off.1.state', 'posted')->where('taken_off.1.invoices.0', $this->invoice->invoice_number));

    ($this->booksTie)();
});

it('Buy and receive takes it off through the screen and says what happened', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->post('/purchasing/buy-and-receive', [
            'request_key' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id,
            'purchased_on' => '2026-10-17', 'bill_number' => 'AYESHA-211026', 'take_off_amount' => '20000',
            'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '1000', 'unit_cost' => '20', 'entry_mode' => 'rate']],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '17,400.00 from our advance; 2,600.00 off'));

    expect(QuickPurchase::query()->sole()->bill->outstanding()->minor)->toBe(0);
});

it('the full purchase page takes it off when the bill is recorded now', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->post('/purchasing/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id, 'order_date' => '2026-10-17',
            'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate']],
            'receive_now' => true, 'bill_now' => true, 'request_key' => (string) Str::uuid(), 'bill_number' => 'CM-77',
            'take_off_amount' => '2000',
        ])->assertSessionHasNoErrors();

    expect(QuickPurchase::query()->sole()->bill->outstanding()->minor)->toBe(0)
        ->and(SetOff::count())->toBe(0)
        ->and(Money::ofTaka((string) SupplierAdvanceApplication::query()->sole()->amount)->minor)->toBe(200_000);
});

it('refuses more than they owe in all, and saves nothing', function () {
    $orders = PurchaseOrder::count();

    expect(fn () => ($this->buy)([
        'lines' => [['product' => $this->d->salt, 'quantity' => '3000', 'unit_cost' => '20.00']],
        'takeOff' => '60000',
    ]))->toThrow(ValidationException::class, 'owes us only 57,331.00 in all');

    expect(PurchaseOrder::count())->toBe($orders)
        ->and(QuickPurchase::count())->toBe(0)
        ->and(SupplierAdvanceApplication::count())->toBe(0)
        ->and(SetOff::count())->toBe(0);
});

it('refuses more than the bill owes', function () {
    expect(fn () => ($this->buy)(['takeOff' => '25000']))->toThrow(ValidationException::class, 'owes only 20,000.00');

    expect(QuickPurchase::count())->toBe(0);
});

it('refuses the one amount together with advances picked one by one', function () {
    expect(fn () => ($this->buy)(['takeOff' => '1000', 'advances' => [['key' => "advance:{$this->advance->id}", 'amount' => '500']]]))
        ->toThrow(ValidationException::class, 'not both');
});

it('an unlinked supplier has no invoice part: only our advance can come off', function () {
    $other = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'KARIM', 'name' => 'Karim Oil Mill']);
    app(RecordSupplierAdvance::class)->pay(supplier: $other, moneyAccount: $this->w->bank, paidOn: '2026-10-05', amount: '1000', createdBy: $this->w->owner);

    $shown = TakeOffWhatTheyOwe::forSupplier($other, $this->w->owner);

    expect($shown['linked'])->toBeFalse()
        ->and($shown['invoices'])->toBe([])
        ->and($shown['total'])->toBe('1000.00');

    expect(fn () => ($this->buy)(['supplier' => $other, 'takeOff' => '1500']))
        ->toThrow(ValidationException::class, 'holds only 1,000.00 of our advance');

    $quick = ($this->buy)(['supplier' => $other, 'takeOff' => '1000']);

    expect($quick->bill->fresh()->outstanding()->minor)->toBe(2_000_000 - 100_000)
        ->and(SetOff::count())->toBe(0);
});

it('a set-off the buyer may not approve waits in the inbox; the bill owes that part until it is approved', function () {
    $clerk = $this->w->sales;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->w->business->id);
    $clerk->givePermissionTo([
        'purchasing.quick.purchase', 'purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record', 'purchasing.bill.record',
        'purchasing.advance.record', 'accounting.set_off.manage',
    ]);
    $clerk = User::query()->find($clerk->id);

    expect(TakeOffWhatTheyOwe::forSupplier($this->supplier, $clerk)['posts_now'])->toBeFalse();

    $quick = ($this->buy)(['takeOff' => '20000', 'by' => $clerk]);
    $setOff = SetOff::query()->sole();

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($setOff->status)->toBe(SetOff::PENDING)
        ->and($quick->held_reason)->toContain("Set-off {$setOff->number} (2,600.00 off their invoices) waits for approval")
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(260_000);

    // The Owner approves it from the inbox: now the bill is settled.
    $this->p->approve($setOff, $this->w->owner);

    expect($setOff->fresh()->status)->toBe(SetOff::POSTED)
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(0);

    ($this->booksTie)();
});

it('refuses the invoice part to a buyer who may not raise set-offs, and saves nothing', function () {
    $clerk = $this->w->sales;
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->w->business->id);
    $clerk->givePermissionTo([
        'purchasing.quick.purchase', 'purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record', 'purchasing.bill.record',
        'purchasing.advance.record',
    ]);
    $clerk = User::query()->find($clerk->id);

    expect(fn () => ($this->buy)(['takeOff' => '20000', 'by' => $clerk]))->toThrow(ValidationException::class, 'may not raise one');

    expect(QuickPurchase::count())->toBe(0)
        ->and(SupplierAdvanceApplication::count())->toBe(0);
});

it('a set-off made with a purchase reverses through the ordinary corrections', function () {
    ($this->buy)(['takeOff' => '20000']);
    $setOff = SetOff::query()->sole();
    $before = ($this->net)();

    $this->p->reverse($setOff, $this->w->owner);

    expect($setOff->fresh()->status)->toBe(SetOff::REVERSED)
        ->and($this->invoice->fresh()->outstanding()->minor)->toBe(3_993_100)
        ->and(($this->net)() - $before)->toBe(0);

    ($this->booksTie)();
});

it('a held bill takes it off when it is approved', function () {
    config(['accounting.purchasing_approvals' => true]);

    ($this->buy)([
        'lines' => [['product' => $this->d->salt, 'quantity' => '10050', 'unit_cost' => '20.00']],
        'takeOff' => '20000',
    ]);
    $approval = PurchaseApproval::query()->sole();

    expect(SupplierAdvanceApplication::count())->toBe(0)
        ->and($approval->payload['follow_ups']['take_off']['amount'])->toBe('20000.00');

    $bill = app(PurchasingApprovalGate::class)->approve($approval, $this->w->manager);

    expect(SupplierAdvanceApplication::count())->toBe(1)
        ->and(SetOff::query()->sole()->status)->toBe(SetOff::POSTED)
        ->and($bill->fresh()->outstanding()->minor)->toBe(20_100_000 - 2_000_000);
});

it('the supplier bill screen takes it off the same way, and the bill page lists the set-off', function () {
    $this->d->supplier = $this->supplier;
    $order = $this->d->order([[$this->d->salt, '1000', '20']]);

    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->post('/purchasing/bills', [
            'purchase_order_id' => $order->id, 'bill_number' => 'AYESHA-211026', 'bill_date' => '2026-10-17',
            'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '1000', 'unit_cost' => '20']],
            'take_off_amount' => '20000',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '17,400.00 from our advance; 2,600.00 off'));

    $bill = SupplierBill::query()->where('bill_number', 'AYESHA-211026')->sole();
    $setOff = SetOff::query()->sole();

    expect($bill->outstanding()->minor)->toBe(0)
        ->and($setOff->status)->toBe(SetOff::POSTED);

    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])
        ->get("/purchasing/bills/{$bill->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('set_offs.0.number', $setOff->number)->where('set_offs.0.amount', '2600.00'));

    ($this->booksTie)();
});
