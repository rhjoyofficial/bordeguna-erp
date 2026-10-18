<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceApplication;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\DiscountWorld;

/**
 * Supplier finance wave A, merged (19 Oct 2026): the supplier ledger (A1),
 * advances (A2) and discounts (A3) working on ONE bill.
 *
 *   advance 5,000 (PO-linked)  ->  bill 10,000 with a 500 trade discount
 *   ->  advance applied 5,000  ->  paid 4,300 with a 200 settlement discount
 *   ->  the bill owes nothing; 2110, 1160 and 2120 are zero for the supplier
 *
 * and every reversal, in dependency order, puts each balance back.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->supplier = $this->d->supplier;

    $this->partyBalance = fn (string $slug) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->where('party_type', $this->supplier->getMorphClass())->where('party_id', $this->supplier->id)
        ->sum('amount_minor');
    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->ledger = fn () => SupplierLedger::for($this->supplier->fresh(), Carbon::parse('2026-07-01')->startOfDay(), Carbon::parse('2026-10-31')->endOfDay());

    $this->booksTie = function () {
        expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
            ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty()
            ->and(($this->ledger)()->isConsistent())->toBeTrue()
            ->and(SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-31')->endOfDay())->isConsistent())->toBeTrue();
    };

    // The whole story up to a settled bill.
    $this->story = function (): array {
        $order = $this->d->order([[$this->d->salt, '500', '20.00']]);
        $advance = app(RecordSupplierAdvance::class)->pay(
            supplier: $this->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-05', amount: '5000',
            createdBy: $this->w->owner, purchaseOrder: $order, reference: 'ADV-1',
        );

        $bill = $this->d->bill($order, ['mode' => 'amount', 'value' => '500', 'reference' => 'INV-100']);
        $trade = SupplierDiscount::query()->where('kind', SupplierDiscount::TRADE)->sole();

        $applications = app(ApplySupplierAdvances::class)->handle($bill->fresh(), [['advance' => $advance, 'amount' => '5000']], '2026-10-12', $this->w->owner);

        $payment = $this->d->pay($bill->fresh(), '4300', '200', 'Supplier letter SL-7', 'Paid within 7 days');
        $settlement = SupplierDiscount::query()->where('kind', '!=', SupplierDiscount::TRADE)->sole();

        return [$order, $advance, $bill, $trade, $applications->sole(), $payment, $settlement];
    };
});

afterEach(fn () => Carbon::setTestNow());

it('settles one bill with an advance, a trade discount, a settlement discount and a payment, and every view ties out', function () {
    /** @var PurchaseOrder $order */
    [$order, $advance, $bill, $trade, $application, $payment, $settlement] = ($this->story)();

    expect($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and($advance->fresh()->open()->minor)->toBe(0)
        ->and($trade->money()->format())->toBe('500.00')
        ->and($settlement->money()->format())->toBe('200.00')
        ->and($settlement->supplier_payment_id)->toBe($payment->id)
        ->and((string) $payment->amount)->toContain('4300');

    // The supplier's own balances on each control account are nil.
    expect(($this->partyBalance)('accounts_payable'))->toBe(0)
        ->and(($this->partyBalance)('supplier_advances'))->toBe(0)
        ->and(($this->partyBalance)('goods_received_not_invoiced'))->toBe(0);

    // The ledger view's cards say the same, account by account.
    $ledger = ($this->ledger)();
    expect($ledger->billedPayable()->minor)->toBe(0)
        ->and($ledger->advances()->minor)->toBe(0)
        ->and($ledger->receivedNotBilled()->minor)->toBe(0)
        ->and($ledger->netExposure()->minor)->toBe(0)
        ->and($ledger->balanceOf(SupplierLedger::ROLE_PAYABLE))->toBe(($this->partyBalance)('accounts_payable'))
        ->and($ledger->balanceOf(SupplierLedger::ROLE_ADVANCES))->toBe(($this->partyBalance)('supplier_advances'))
        ->and($ledger->balanceOf(SupplierLedger::ROLE_GRNI))->toBe(($this->partyBalance)('goods_received_not_invoiced'));

    // GRNI was tagged with the supplier on the receipt, and cleared by the bill.
    $grni = Account::bySlug('goods_received_not_invoiced')->id;
    expect(JournalLine::where('account_id', $grni)->whereNull('party_id')->count())->toBe(0)
        ->and(JournalLine::where('account_id', $grni)->where('party_id', $this->supplier->id)->count())->toBe(2);

    // Both discounts came off the goods: 10,000 - 500 - 200 on hand, none to 4950.
    expect(($this->balance)('stock_on_hand'))->toBe(930000)
        ->and(($this->balance)('discounts_received'))->toBe(0)
        ->and((string) StockBatch::query()->where('product_id', $this->d->salt->id)->sole()->unit_cost)->toBe('18.6000');

    // Money out: 5,000 advance + 4,300 payment.
    expect(($this->balance)('cash_at_bank'))->toBe(-930000);

    // The statement lists each money event once: the bill, both discounts, the
    // application (as its adjustment) and the payment; nothing twice.
    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-31')->endOfDay());
    $kinds = $statement->lines->pluck('kind')->countBy();
    expect($statement->closing()->minor)->toBe(0)
        ->and($kinds['discount'] ?? 0)->toBe(2)
        ->and($statement->lines->filter(fn ($line) => str_contains((string) $line->label, 'SD-'))->count())->toBe(2);

    ($this->booksTie)();
});

it('never lets the advance, the discounts and the payment together exceed the bill', function () {
    $order = $this->d->order([[$this->d->salt, '500', '20.00']]);
    $advance = app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-05', amount: '9800',
        createdBy: $this->w->owner, purchaseOrder: $order,
    );
    $bill = $this->d->bill($order, ['mode' => 'amount', 'value' => '500', 'reference' => 'INV-100']);

    // 9,500 is owed after the trade discount: 9,600 of advance is refused.
    expect(fn () => app(ApplySupplierAdvances::class)->handle($bill->fresh(), [['advance' => $advance, 'amount' => '9600']], '2026-10-12', $this->w->owner))
        ->toThrow(ValidationException::class);

    app(ApplySupplierAdvances::class)->handle($bill->fresh(), [['advance' => $advance, 'amount' => '9400']], '2026-10-12', $this->w->owner);

    // 100 is left: a 100 payment with a 50 discount is more than that.
    expect(fn () => $this->d->pay($bill->fresh(), '100', '50', 'Letter', 'Early'))->toThrow(ValidationException::class)
        ->and(SupplierPayment::count())->toBe(0)
        ->and(SupplierDiscount::count())->toBe(1);

    $this->d->pay($bill->fresh(), '50', '50', 'Letter', 'Early');
    expect($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and($advance->fresh()->open()->format())->toBe('400.00');

    ($this->booksTie)();
});

it('reverses in dependency order across the features and restores every balance', function () {
    [$order, $advance, $bill, $trade, $application, $payment, $settlement] = ($this->story)();
    $by = $this->w->owner;

    // Out of order is refused at each step.
    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment->fresh(), $by, 'Wrong'))->toThrow(ValidationException::class, 'settlement discount')
        ->and(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $by, 'Wrong'))->toThrow(DomainException::class)
        ->and(fn () => app(ReverseSupplierAdvance::class)->handle($advance->fresh(), $by, 'Wrong'))->toThrow(ValidationException::class);

    // 1. The settlement discount: 200 owed again, cost back on the batch.
    $this->d->reverse($settlement, $this->w->financeA);
    expect($bill->fresh()->outstanding()->format())->toBe('200.00')
        ->and(($this->partyBalance)('accounts_payable'))->toBe(-20000);

    // 2. The payment: 4,500 owed.
    app(ReverseSupplierPayment::class)->handle($payment->fresh(), $by, 'Wrong cheque');
    expect($bill->fresh()->outstanding()->format())->toBe('4,500.00')
        ->and(($this->partyBalance)('accounts_payable'))->toBe(-450000);

    // 3. The trade discount: 5,000 owed, the batch back at cost.
    $this->d->reverse($trade->fresh(), $this->w->financeA);
    expect($bill->fresh()->outstanding()->format())->toBe('5,000.00');

    // The bill still refuses: the advance application stands on it.
    expect(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $by, 'Wrong'))->toThrow(DomainException::class, 'advance application');

    // 4. The application: the advance is open again, the bill owes 10,000.
    app(ReverseSupplierAdvanceApplication::class)->handle($application->fresh(), $by, 'Wrong bill');
    expect($advance->fresh()->open()->format())->toBe('5,000.00')
        ->and($bill->fresh()->outstanding()->format())->toBe('10,000.00')
        ->and(($this->partyBalance)('supplier_advances'))->toBe(500000);

    // 5. The bill: received, not billed.
    app(ReverseSupplierBill::class)->handle($bill->fresh(), $by, 'Wrong supplier invoice');
    expect(($this->partyBalance)('accounts_payable'))->toBe(0)
        ->and(($this->partyBalance)('goods_received_not_invoiced'))->toBe(-1000000)
        ->and(($this->balance)('stock_on_hand'))->toBe(1000000)
        ->and((string) StockBatch::query()->where('product_id', $this->d->salt->id)->sole()->unit_cost)->toBe('20.0000');

    // 6. The advance itself.
    app(ReverseSupplierAdvance::class)->handle($advance->fresh(), $by, 'Paid the wrong supplier');
    expect(($this->partyBalance)('supplier_advances'))->toBe(0)
        ->and(($this->balance)('cash_at_bank'))->toBe(0)
        ->and(SupplierAdvanceApplication::query()->whereNull('reversed_at')->count())->toBe(0)
        ->and(SupplierDiscount::query()->whereNull('reversed_at')->count())->toBe(0)
        ->and(SupplierAdvance::query()->whereNull('reversed_at')->count())->toBe(0);

    $ledger = ($this->ledger)();
    expect($ledger->receivedNotBilled()->format())->toBe('10,000.00')
        ->and($ledger->billedPayable()->minor)->toBe(0)
        ->and($ledger->advances()->minor)->toBe(0);

    ($this->booksTie)();
});

it('saves a bill with a trade discount and applied advances in one request, or neither', function () {
    $order = $this->d->order([[$this->d->salt, '500', '20.00']]);
    $advance = app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-05', amount: '9800',
        createdBy: $this->w->owner, purchaseOrder: $order,
    );
    $line = $order->lines->first();

    $form = fn (string $advanceAmount) => [
        'purchase_order_id' => $order->id, 'bill_number' => 'INV-100', 'bill_date' => '2026-10-12',
        'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '500', 'unit_cost' => '20.00']],
        'discount_mode' => 'amount', 'discount_value' => '500', 'discount_reference' => 'INV-100',
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => $advanceAmount]],
    ];

    // 9,600 is more than the 9,500 owed after the discount: no bill, no discount.
    $this->actingAs($this->w->owner)->post('/purchasing/bills', $form('9600'))->assertSessionHasErrors();
    expect(SupplierBill::count())->toBe(0)
        ->and(SupplierDiscount::count())->toBe(0)
        ->and(SupplierAdvanceApplication::count())->toBe(0);

    $this->actingAs($this->w->owner)->post('/purchasing/bills', $form('9500'))->assertSessionHasNoErrors();
    expect(SupplierBill::sole()->outstanding()->minor)->toBe(0)
        ->and(SupplierDiscount::sole()->money()->format())->toBe('500.00')
        ->and($advance->fresh()->open()->format())->toBe('300.00');

    ($this->booksTie)();
});
