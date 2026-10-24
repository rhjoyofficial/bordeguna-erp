<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): the payment typed with Buy and receive
 * and with the full purchase page ("Bill now" + "Pay now") split across
 * several accounts; the rest of the bill stays owed to the supplier.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->supplier = $this->d->supplier;

    $this->bkash = MoneyAccount::create([
        'business_id' => $this->w->business->id, 'name' => 'bKash Merchant', 'type' => 'mfs', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->buy = fn (array $overrides = []) => app(RecordQuickPurchase::class)->handle(...($overrides + [
        'requestKey' => (string) Str::uuid(),
        'supplier' => $this->supplier,
        'warehouse' => $this->d->warehouse,
        'purchasedOn' => '2026-10-17',
        'lines' => [['product' => $this->d->salt, 'quantity' => '500', 'unit_cost' => '20.00']],
        'billNumber' => 'CM-501',
        'by' => $this->w->owner,
    ]));

    $this->creditOf = fn (SupplierPayment $payment, MoneyAccount $account) => (int) Journal::query()
        ->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->sole()
        ->lines()->where('money_account_id', $account->id)->sum('amount_minor');

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

afterEach(fn () => Carbon::setTestNow());

it('Buy and receive pays Tk 10,000 of a Tk 10,000 bill from the bank, bKash and cash in one payment', function () {
    $quick = ($this->buy)(['payment' => [
        'money_account' => $this->w->bank, 'amount' => '10000',
        'split_lines' => [['money_account' => $this->bkash, 'amount' => '2500'], ['money_account' => $this->w->cash, 'amount' => '1500']],
    ]]);
    $payment = $quick->payment;

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and(SupplierPayment::count())->toBe(1)
        ->and(($this->creditOf)($payment, $this->w->bank))->toBe(-600000)
        ->and(($this->creditOf)($payment, $this->bkash))->toBe(-250000)
        ->and(($this->creditOf)($payment, $this->w->cash))->toBe(-150000)
        ->and($quick->bill->outstanding()->minor)->toBe(0);

    ($this->booksTie)();
});

it('Buy and receive: what the accounts do not pay stays owed to the supplier', function () {
    $quick = ($this->buy)(['payment' => [
        'money_account' => $this->w->bank, 'amount' => '6000',
        'split_lines' => [['money_account' => $this->bkash, 'amount' => '1000']],
    ]]);

    expect($quick->bill->outstanding()->minor)->toBe(400000)
        ->and(($this->creditOf)($quick->payment, $this->w->bank))->toBe(-500000)
        ->and(($this->creditOf)($quick->payment, $this->bkash))->toBe(-100000);

    ($this->booksTie)();
});

it('a held bill keeps the split payment and makes it, split, on approval', function () {
    config(['accounting.purchasing_approvals' => true]);

    $quick = ($this->buy)([
        'lines' => [['product' => $this->d->salt, 'quantity' => '10050', 'unit_cost' => '20.00']],
        'payment' => ['money_account' => $this->w->bank, 'amount' => '50000', 'split_lines' => [['money_account' => $this->bkash, 'amount' => '20000']]],
    ]);
    $approval = PurchaseApproval::query()->sole();

    expect($quick->status)->toBe(QuickPurchase::AWAITING_BILL_APPROVAL)
        ->and($approval->payload['follow_ups']['quick_payment']['split_lines'][0]['amount'])->toBe('20000.00');

    $this->actingAs($this->w->manager)->get(route('purchasing.approvals.show', $approval))->assertOk()
        ->assertInertia(fn ($page) => $page->where('approval.details', fn ($rows) => collect($rows)->contains(
            fn ($row) => $row['label'] === 'Payment made on approval' && str_contains($row['value'], 'of which Tk 20000.00 from bKash Merchant'))));

    app(PurchasingApprovalGate::class)->approve($approval, $this->w->manager);
    $payment = SupplierPayment::query()->sole();

    expect(($this->creditOf)($payment, $this->w->bank))->toBe(-3_000_000)
        ->and(($this->creditOf)($payment, $this->bkash))->toBe(-2_000_000);

    ($this->booksTie)();
});

it('the full purchase page splits "Pay now" when the bill is recorded now', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])->post('/purchasing/purchase-orders', [
        'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id, 'order_date' => '2026-10-17',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate']],
        'receive_now' => true, 'bill_now' => true, 'pay_now' => true, 'request_key' => (string) Str::uuid(), 'bill_number' => 'CM-9',
        'payment_money_account_id' => $this->w->bank->id, 'payment_amount' => '1500',
        'split_lines' => [['money_account_id' => $this->bkash->id, 'amount' => '500']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $quick = QuickPurchase::query()->sole();

    expect($quick->bill->outstanding()->minor)->toBe(50000)
        ->and(($this->creditOf)($quick->payment, $this->w->bank))->toBe(-100000)
        ->and(($this->creditOf)($quick->payment, $this->bkash))->toBe(-50000);
});

it('an advance before the bill is paid from one account: a split is refused and nothing is saved', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])->post('/purchasing/purchase-orders', [
        'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id, 'order_date' => '2026-10-17',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate']],
        'pay_now' => true, 'payment_money_account_id' => $this->w->bank->id, 'payment_amount' => '1500',
        'split_lines' => [['money_account_id' => $this->bkash->id, 'amount' => '500']],
    ])->assertSessionHasErrors('split_lines');

    expect(PurchaseOrder::count())->toBe(0)
        ->and(SupplierAdvance::count())->toBe(0);
});

it('Buy and receive screen sends split lines', function () {
    $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id])->post('/purchasing/buy-and-receive', [
        'request_key' => (string) Str::uuid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-17', 'bill_number' => 'CM-10',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate']],
        'pay_now' => true, 'payment_money_account_id' => $this->w->bank->id, 'payment_amount' => '2000',
        'split_lines' => [['money_account_id' => $this->w->cash->id, 'amount' => '700']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $payment = SupplierPayment::query()->sole();

    expect(($this->creditOf)($payment, $this->w->bank))->toBe(-130000)
        ->and(($this->creditOf)($payment, $this->w->cash))->toBe(-70000);
});
