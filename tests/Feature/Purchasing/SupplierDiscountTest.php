<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Supplier discounts (ChatGPT ruling P3, 18 Oct 2026; tests 17 to 23).
 *
 * A trade discount on the supplier's bill and a settlement discount taken when
 * paying both reduce what the goods COST: the on-hand share restates the
 * batches down (Cr 1150), the share of units already gone credits cost of
 * sales (purchasing.discount_consumed); never 4950, never 5400 PPV. Refused
 * when the goods cannot be identified, without evidence or permission, or when
 * it would count a discount twice; undone only through the corrections
 * process, which puts the cost back on the same units.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->owner = $this->w->owner;
    $this->manager = $this->w->manager;
    $this->warehouse = $this->d->warehouse;
    $this->salt = $this->d->salt;
    $this->sugar = $this->d->sugar;
    $this->service = $this->d->service;
    $this->supplier = $this->d->supplier;

    $this->order = fn (array $products, string $receivedOn = '2026-10-10'): PurchaseOrder => $this->d->order($products, $receivedOn);
    $this->bill = fn (PurchaseOrder $order, ?array $discount = null, string $number = 'INV-100', string $on = '2026-10-12'): SupplierBill => $this->d->bill($order, $discount, $number, $on);
    $this->pay = fn (SupplierBill $bill, string $amount, ?string $discount = null, ?string $evidence = null, ?string $reason = null, ?User $by = null): SupplierPayment => $this->d->pay($bill, $amount, $discount, $evidence, $reason, $by);
    $this->reverse = fn (SupplierDiscount $discount, User $by): CorrectionResult => $this->d->reverse($discount, $by);

    $this->batchOf = fn (PurchaseOrder $order, int $index = 0): StockBatch => StockBatch::query()
        ->whereHas('movements', fn ($q) => $q->where('reason', 'receipt'))
        ->where('product_id', $order->lines[$index]->product_id)->sole();

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->booksTie = function () {
        expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
            ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('17: a trade discount on a batch fully on hand lowers its cost and Stock on Hand exactly, and touches neither 4950 nor PPV', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $batch = ($this->batchOf)($order);

    $bill = ($this->bill)($order, ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-100']);
    $discount = SupplierDiscount::sole();
    $batch->refresh();

    // 50 off 25 kg = 2.00 a kg: 20.00 becomes 18.00.
    expect($discount->number)->toBe('SD-2026-27-0001')
        ->and($discount->kind)->toBe(SupplierDiscount::TRADE)
        ->and((int) $discount->on_hand_paisa)->toBe(5000)
        ->and((int) $discount->consumed_paisa)->toBe(0)
        ->and((string) $batch->unit_cost)->toBe('18.0000')
        ->and($bill->fresh()->outstanding()->format())->toBe('450.00');

    $movement = StockMovement::where('batch_id', $batch->id)->where('reason', 'purchase_discount')->sole();
    expect((string) $movement->value_delta)->toBe('50.0000')
        ->and($movement->direction->value)->toBe('out')
        ->and((string) $movement->quantity)->toBe('0.0000');

    // Receipt Dr 500 / bill Cr AP 500 / discount Dr AP 50, Cr Stock 50.
    expect(($this->balance)('stock_on_hand'))->toBe(45000)
        ->and(($this->balance)('accounts_payable'))->toBe(-45000)
        ->and(($this->balance)('discounts_received'))->toBe(0)
        ->and(($this->balance)('purchase_price_variance'))->toBe(0)
        ->and(($this->balance)('purchase_discount_consumed'))->toBe(0);

    $journal = Journal::find($discount->journal_id);
    expect($journal->source_id)->toBe($discount->id)
        ->and($journal->balance()->isZero())->toBeTrue()
        ->and($journal->lines()->where('amount_minor', 5000)->sole()->party_id)->toBe($this->supplier->id);

    // The statement lists it as its own numbered document and agrees with the ledger.
    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());
    expect($statement->isConsistent())->toBeTrue()
        ->and($statement->lines->pluck('kind')->all())->toContain('discount')
        ->and($statement->lines->firstWhere('kind', 'discount')->label)->toContain('SD-2026-27-0001')
        ->and($statement->closing()->format())->toBe('450.00');

    ($this->booksTie)();
});

it('18: a trade discount on a partly used batch splits between stock on hand and cost of sales', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $batch = ($this->batchOf)($order);

    app(RecordStockOut::class)->handle(product: $this->salt, warehouse: $this->warehouse, quantity: '5', performedBy: $this->owner, occurredAt: '2026-10-11');

    ($this->bill)($order, ['mode' => 'amount', 'value' => '100', 'reference' => 'INV-100']);
    $discount = SupplierDiscount::sole();
    $allocation = $discount->allocations->sole();
    $batch->refresh();

    // 100 over 25 kg: 80 for the 20 kg here, 20 for the 5 kg gone. 80 / 20 = 4.00 a kg off.
    expect((int) $allocation->on_hand_share_paisa)->toBe(8000)
        ->and((int) $allocation->consumed_share_paisa)->toBe(2000)
        ->and((string) $allocation->quantity_on_hand)->toBe('20.0000')
        ->and((string) $batch->unit_cost)->toBe('16.0000')
        // The 5 kg left at 20.00; history does not move.
        ->and((string) StockMovement::where('direction', 'out')->where('quantity', '>', 0)->sole()->unit_cost)->toBe('20.0000')
        ->and(($this->balance)('purchase_discount_consumed'))->toBe(-2000)
        ->and(($this->balance)('accounts_payable'))->toBe(-40000);
});

it('spreads a percentage over the bill by value, to the paisa, and the batch cost lands on the expected value', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00'], [$this->sugar, '7', '33.33']]);

    // Goods 500.00 + 233.31 = 733.31; 7% = 51.33 (51.3317 rounded half up).
    ($this->bill)($order, ['mode' => 'percent', 'value' => '7', 'reference' => 'INV-100']);
    $discount = SupplierDiscount::sole();

    expect($discount->money()->format())->toBe('51.33')
        ->and($discount->allocations->sum('share_paisa'))->toBe(5133)
        ->and((string) $discount->percent)->toBe('7.0000');

    // By value: 5133 x 50000 / 73331 = 3499.8 -> 3499, + the leftover paisa to the largest: 3500.
    $salt = $discount->allocations->firstWhere('stock_batch_id', ($this->batchOf)($order, 0)->id);
    $sugar = $discount->allocations->firstWhere('stock_batch_id', ($this->batchOf)($order, 1)->id);
    expect((int) $salt->share_paisa)->toBe(3500)
        ->and((int) $sugar->share_paisa)->toBe(1633)
        // 35.00 / 25 = 1.40: 18.6000. 16.33 / 7 = 2.332857 -> 2.3329: 30.9971.
        ->and((string) ($this->batchOf)($order, 0)->unit_cost)->toBe('18.6000')
        ->and((string) ($this->batchOf)($order, 1)->unit_cost)->toBe('30.9971')
        ->and(($this->balance)('stock_on_hand'))->toBe(73331 - 5133);

    ($this->booksTie)();
});

it('19: a settlement discount with evidence closes the bill and reduces the goods cost, not 4950', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order);

    $payment = ($this->pay)($bill, '480', '20', 'Letter from supplier 14 Oct', 'Paid within 7 days, 4% off');
    $discount = SupplierDiscount::sole();

    expect($discount->kind)->toBe(SupplierDiscount::SETTLEMENT)
        ->and($discount->supplier_payment_id)->toBe($payment->id)
        ->and($discount->discount_on->toDateString())->toBe('2026-10-16')
        ->and($discount->reference)->toBe('Letter from supplier 14 Oct')
        ->and($bill->fresh()->isSettled())->toBeTrue()
        ->and((string) ($this->batchOf)($order)->unit_cost)->toBe('19.2000')
        ->and(($this->balance)('discounts_received'))->toBe(0)
        ->and(($this->balance)('accounts_payable'))->toBe(0)
        ->and(($this->balance)('stock_on_hand'))->toBe(48000)
        ->and((int) JournalLine::where('account_id', $this->w->bank->account_id)->sum('amount_minor'))->toBe(-48000)
        ->and(Journal::find($discount->journal_id)->journal_date->toDateString())->toBe('2026-10-16');

    ($this->booksTie)();
});

it('20: a short payment with no discount entered is a part payment, never a discount', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order);

    ($this->pay)($bill, '480');

    expect(SupplierDiscount::count())->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('20.00')
        ->and((string) ($this->batchOf)($order)->unit_cost)->toBe('20.0000');
});

it('refuses a settlement discount without evidence, without a reason, or without the permission', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order);

    expect(fn () => ($this->pay)($bill, '480', '20', '', 'Prompt payment'))
        ->toThrow(ValidationException::class, 'evidence')
        ->and(fn () => ($this->pay)($bill, '480', '20', 'Letter', ''))
        ->toThrow(ValidationException::class, 'Say why');

    // The Manager may pay, but not take a discount: nothing is written.
    expect($this->manager->can('purchasing.payment.record'))->toBeTrue()
        ->and($this->manager->can('purchasing.discount.settle'))->toBeFalse()
        ->and(fn () => ($this->pay)($bill, '480', '20', 'Letter', 'Prompt payment', $this->manager))
        ->toThrow(ValidationException::class, 'permission to settle discounts');

    expect(SupplierPayment::count())->toBe(0)
        ->and(SupplierDiscount::count())->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('500.00');

    // Payment and discount together may not exceed what is owed.
    expect(fn () => ($this->pay)($bill, '490', '20', 'Letter', 'Prompt payment'))
        ->toThrow(ValidationException::class, 'the payment and the discount together are more');
});

it('21: refuses a discount whose goods cannot be identified, and never falls back to PPV or 4950', function () {
    // A service bill: nothing went into stock.
    $serviceOrder = ($this->order)([[$this->service, '1', '300.00']]);

    expect(fn () => ($this->bill)($serviceOrder, ['mode' => 'amount', 'value' => '30', 'reference' => 'LAB-1'], 'LAB-1'))
        ->toThrow(ValidationException::class, 'nothing on this bill went into stock');

    // A line part-returned to the supplier: the billed units cannot be named.
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    app(RecordPurchaseReturn::class)->handle($order, [['line' => $order->lines[0], 'quantity' => '5']], $this->owner);
    $order = $order->fresh('lines');

    expect(fn () => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-200',
        billDate: '2026-10-12',
        lines: [['line' => $order->lines[0], 'quantity' => '20', 'unitCost' => '20.00']],
        createdBy: $this->owner,
        purchaseOrder: $order,
        tradeDiscount: ['mode' => 'amount', 'value' => '20', 'reference' => 'INV-200'],
    ))->toThrow(ValidationException::class, 'returned to the supplier');

    // Refused means nothing written: no bill, no discount, nothing on PPV or 4950.
    expect(SupplierBill::count())->toBe(0)
        ->and(SupplierDiscount::count())->toBe(0)
        ->and(($this->balance)('purchase_price_variance'))->toBe(0)
        ->and(($this->balance)('discounts_received'))->toBe(0);
});

it('22: refuses the same reference twice and double counting between a discount and a 4950 adjustment', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order, ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-100']);

    // The same supplier reference on a settlement discount.
    expect(fn () => ($this->pay)($bill, '400', '10', 'inv-100', 'Same paper'))
        ->toThrow(ValidationException::class, 'cannot be counted twice');

    // A 4950 supplier discount adjustment on a bill whose goods already took one.
    expect(fn () => app(RecordPartyAdjustment::class)->handle('supplier_discount', '2026-10-16', null, $this->supplier, [], [$bill->id => '10'], 'Discount', $this->owner))
        ->toThrow(ValidationException::class, 'already carries discount SD-2026-27-0001');

    // And the other way: a bill with a 4950 adjustment takes no cost discount.
    $other = ($this->order)([[$this->sugar, '10', '10.00']]);
    $otherBill = ($this->bill)($other, null, 'INV-300');
    app(RecordPartyAdjustment::class)->handle('supplier_discount', '2026-10-16', null, $this->supplier, [], [$otherBill->id => '5'], 'Rebate', $this->owner);

    expect(fn () => ($this->pay)($otherBill, '50', '5', 'Letter 9', 'Prompt payment'))
        ->toThrow(ValidationException::class, 'already has a discount received');

    // The discount's own adjustment is not removable from the Adjustments screen.
    expect(fn () => app(RecordPartyAdjustment::class)->remove(PartyAdjustment::where('kind', 'supplier_trade_discount')->sole(), $this->owner))
        ->toThrow(ValidationException::class, 'belongs to discount SD-2026-27-0001');
});

it('23: reverses through the corrections process: cost back on the batch, bill owed again, books tying', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order, ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-100']);
    $discount = SupplierDiscount::sole();

    // The bill cannot be reversed while its discount stands.
    expect(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $this->owner, 'x'))
        ->toThrow(DomainException::class, 'carries discount SD-2026-27-0001');

    $result = ($this->reverse)($discount, $this->w->financeA);
    $discount->refresh();

    expect($discount->isReversed())->toBeTrue()
        ->and($result->reversalJournal->reverses_journal_id)->toBe($discount->journal_id)
        ->and((string) ($this->batchOf)($order)->unit_cost)->toBe('20.0000')
        ->and($bill->fresh()->outstanding()->format())->toBe('500.00')
        ->and(PartyAdjustment::count())->toBe(0)
        ->and(($this->balance)('stock_on_hand'))->toBe(50000)
        ->and(($this->balance)('accounts_payable'))->toBe(-50000)
        ->and(StockMovement::where('reason', 'purchase_discount')->count())->toBe(2);

    $statement = SupplierStatement::for($this->supplier, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());
    expect($statement->isConsistent())->toBeTrue()
        ->and($statement->lines->pluck('kind')->all())->toContain('discount_reversed')
        ->and($statement->closing()->format())->toBe('500.00');

    ($this->booksTie)();

    // Once is enough.
    expect(fn () => ($this->reverse)($discount, $this->w->financeA))->toThrow(CorrectionBlockedException::class);
});

it('blocks a reversal once units of the discounted batch have moved, and a payment reversal while its discount stands', function () {
    $order = ($this->order)([[$this->salt, '25', '20.00']]);
    $bill = ($this->bill)($order);
    $payment = ($this->pay)($bill, '480', '20', 'Letter 14 Oct', 'Prompt payment');
    $discount = SupplierDiscount::sole();

    expect(fn () => app(ReverseSupplierPayment::class)->handle($payment, $this->owner, 'Wrong cheque'))
        ->toThrow(ValidationException::class, 'Reverse the discount on its bill first');

    app(RecordStockOut::class)->handle(product: $this->salt, warehouse: $this->warehouse, quantity: '1', performedBy: $this->owner, occurredAt: '2026-10-17');

    $availability = app(SupplierDiscountActionPolicy::class)->availability($discount->fresh(), $this->w->financeA, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('stock_moved');
});

it('keeps the head its own: 5170 under Cost of Sales, owned by Purchasing, mapped as purchasing.discount_consumed', function () {
    $head = Account::bySlug('purchase_discount_consumed');

    expect($head->code)->toBe('5170')
        ->and(AccountMapping::forKeyOn('purchasing.discount_consumed', Carbon::today())?->account_id)->toBe($head->id)
        ->and(VoucherAccounts::refusal($head, Carbon::today()))->not->toBeNull();
});
