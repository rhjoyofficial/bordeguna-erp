<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\ReturnPurchasedGoods;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): "Return" from
 * the goods receipt or the supplier bill. The goods leave the exact batch the
 * delivery made (refused once it is used up); an unpaid bill is reduced by a
 * credit note; what a paid bill cannot take is kept as a supplier advance or
 * refunded into money accounts (split allowed), the user's choice; the
 * returned goods' share of landed cost is a loss with the reason. The books
 * tie after every step.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    // 50 kg of salt at Tk 20 received 10 Oct; billed 12 Oct for Tk 1,000.
    $this->order = $this->d->order([[$this->d->salt, '50', '20.00']]);
    $this->receipt = GoodsReceipt::query()->where('purchase_order_id', $this->order->id)->sole();
    $this->batch = StockBatch::query()->sole();

    $this->return = fn (string $quantity, array $options = []) => app(ReturnPurchasedGoods::class)->handle(...[
        'order' => $this->order->fresh('lines'),
        'lineReturns' => [['line' => $this->order->lines()->first(), 'quantity' => $quantity]],
        'returnedOn' => '2026-10-16',
        'reason' => 'Wet sacks, supplier takes them back',
        'by' => $this->w->owner,
        'receipt' => $this->receipt,
        ...$options,
    ]);

    $this->balanceOf = fn (string $slug) => (int) DB::table('journal_lines')->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('unpaid bill: the return reduces what is owed by a credit note, from the same batch', function () {
    $bill = $this->d->bill($this->order);

    $result = ($this->return)('10');

    expect((float) $this->batch->fresh()->current_quantity)->toBe(40.0)
        ->and($result['credit_note'])->not->toBeNull()
        ->and($result['credit_note']->credit_note_number)->toBe("RET-{$result['return']->id}")
        ->and((float) $result['credit_note']->total)->toBe(200.0)
        ->and($result['advance'])->toBeNull()
        ->and($bill->fresh()->outstanding()->minor)->toBe(80000)
        ->and($result['return']->returned_at->toDateString())->toBe('2026-10-16')
        ->and(($this->balanceOf)('goods_received_not_invoiced'))->toBe(0)
        ->and($this->m->problems())->toBe([]);
});

it('paid bill, kept as an advance: offered on the next purchase, and the return waits for it before reversing', function () {
    $bill = $this->d->bill($this->order);
    $this->d->pay($bill, '1000');

    $result = ($this->return)('10');
    $advance = $result['advance'];

    expect($result['credit_note'])->toBeNull()
        ->and($advance->kind)->toBe('return_credit')
        ->and((int) $advance->purchase_return_id)->toBe($result['return']->id)
        ->and($advance->open()->minor)->toBe(20000)
        ->and(($this->balanceOf)('supplier_advances'))->toBe(20000)
        ->and(($this->balanceOf)('goods_received_not_invoiced'))->toBe(0)
        ->and($result['return']->creditedTotal()->minor)->toBe(20000)
        ->and($this->m->problems())->toBe([]);

    // "Allocate advance" on his next purchase offers it.
    expect(collect(SupplierAdvanceBook::available($this->d->supplier))->pluck('advance.id')->filter()->all())->toContain($advance->id);

    // The return cannot be reversed while the advance keeps its credit.
    $availability = app(PurchaseReturnActionPolicy::class)->availability($result['return'], $this->w->financeB, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('message')->implode(' '))->toContain("Advance {$advance->number} keeps the credit");
});

it('paid bill, refunded: the supplier pays back into two accounts in one step', function () {
    $bill = $this->d->bill($this->order);
    $this->d->pay($bill, '1000');
    $bankBefore = ($this->balanceOf)('cash_at_bank');

    $result = ($this->return)('10', [
        'settle' => ReturnPurchasedGoods::SETTLE_REFUND,
        'refundLines' => [
            ['money_account' => $this->w->bank, 'amount' => '150'],
            ['money_account' => $this->w->cash, 'amount' => '50'],
        ],
    ]);

    expect($result['refunds'])->toHaveCount(2)
        ->and($result['pending'])->toBe([])
        ->and($result['advance']->fresh()->open()->minor)->toBe(0)
        ->and(($this->balanceOf)('cash_at_bank') - $bankBefore)->toBe(15000)
        ->and(($this->balanceOf)('supplier_advances'))->toBe(0)
        ->and(SupplierAdvanceRefund::query()->count())->toBe(2)
        ->and($this->m->problems())->toBe([]);
});

it('part-paid bill: the credit takes what the bill still owes, the rest becomes an advance', function () {
    $bill = $this->d->bill($this->order);
    $this->d->pay($bill, '900');

    $result = ($this->return)('10');

    expect((float) $result['credit_note']->total)->toBe(100.0)
        ->and($bill->fresh()->outstanding()->minor)->toBe(0)
        ->and($result['advance']->money()->minor)->toBe(10000)
        ->and($this->m->problems())->toBe([]);
});

it('never billed: the goods go back and nothing is owed, the bill will be for less', function () {
    $result = ($this->return)('10');

    expect($result['credit_note'])->toBeNull()
        ->and($result['advance'])->toBeNull()
        ->and($this->order->lines()->first()->billableQuantity())->toBe('40.0000')
        ->and(SupplierCreditNote::query()->count())->toBe(0)
        ->and(($this->balanceOf)('goods_received_not_invoiced'))->toBe(-80000)
        ->and($this->m->problems())->toBe([]);

    // A refund asked for unbilled goods is refused, nothing written.
    expect(fn () => ($this->return)('5', ['settle' => ReturnPurchasedGoods::SETTLE_REFUND, 'refundLines' => [['money_account' => $this->w->bank, 'amount' => '100']]]))
        ->toThrow(ValidationException::class);
    expect(PurchaseReturn::query()->count())->toBe(1);
});

it('writes the returned goods\' landed cost share off to the loss head with the reason', function () {
    Carbon::setTestNow('2026-10-17 10:00:00');
    // Tk 100 of carrying over 50 kg: the batch carries Tk 22 a kg.
    $lc = app(SaveLandedCost::class)->handle($this->receipt, '2026-10-11', [[
        'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => '100', 'supplier_id' => null,
        'money_account_id' => $this->w->bank->id, 'expense_account_id' => null, 'description' => null,
    ]], $this->w->owner);
    $lc = app(SubmitLandedCost::class)->handle($lc, $this->w->owner);
    app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $this->w->owner);
    expect((string) $this->batch->fresh()->unit_cost)->toBe('22.0000');

    $bill = $this->d->bill($this->order);
    ($this->return)('10');

    $loss = AccountMapping::resolve('purchasing.return_landed_cost_loss', Carbon::today());
    $line = DB::table('journal_lines')->where('account_id', $loss->id)->sole();

    expect($loss->system_slug)->toBe('inventory_write_off')
        ->and((int) $line->amount_minor)->toBe(2000)
        ->and($line->memo)->toContain('Wet sacks')
        ->and($bill->fresh()->outstanding()->minor)->toBe(80000)
        ->and($this->m->problems())->toBe([]);
});

it('refuses a batch already used up, a short reason, a future date and a date before the delivery, writing nothing', function () {
    app(RecordStockOut::class)->handle(product: $this->d->salt, warehouse: $this->d->warehouse, quantity: '45', performedBy: $this->w->owner, occurredAt: '2026-10-15');

    expect(fn () => ($this->return)('10'))->toThrow(RuntimeException::class);
    expect(fn () => ($this->return)('1', ['reason' => 'bad']))->toThrow(ValidationException::class);
    expect(fn () => ($this->return)('1', ['returnedOn' => '2026-10-30']))->toThrow(ValidationException::class);
    expect(fn () => ($this->return)('1', ['returnedOn' => '2026-10-05']))->toThrow(ValidationException::class);

    expect(PurchaseReturn::query()->count())->toBe(0)
        ->and(SupplierAdvance::query()->count())->toBe(0)
        ->and((float) $this->batch->fresh()->current_quantity)->toBe(5.0);
});

describe('the screens', function () {
    it('offers the Return popup on the receipt and the bill pages', function () {
        $bill = $this->d->bill($this->order);

        $this->actingAs($this->w->owner)->get("/purchasing/receipts/{$this->receipt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('return_form.lines.0.can_return', '50.0000')
                ->where('return_form.lines.0.batches.0', $this->batch->batch_number)
                ->where('return_form.bills_owe', '1000.00')
                ->etc());

        $this->actingAs($this->w->owner)->get("/purchasing/bills/{$bill->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('return_form.lines.0.can_return', '50.0000')->etc());
    });

    it('records from the receipt page, once per key, and refuses a reader without the permission', function () {
        $bill = $this->d->bill($this->order);
        $key = (string) Str::uuid();
        $payload = [
            'returned_on' => '2026-10-16',
            'reason' => 'Wet sacks, supplier takes them back',
            'request_key' => $key,
            'lines' => [['purchase_order_line_id' => $this->order->lines()->first()->id, 'quantity' => '10']],
            'settle' => 'advance',
            'refunds' => [],
        ];

        $this->actingAs($this->w->sales)->post("/purchasing/receipts/{$this->receipt->id}/return", $payload)->assertForbidden();

        $this->actingAs($this->w->owner)->post("/purchasing/receipts/{$this->receipt->id}/return", $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->w->owner)->from("/purchasing/receipts/{$this->receipt->id}")
            ->post("/purchasing/receipts/{$this->receipt->id}/return", $payload)
            ->assertSessionHasErrors('lines');

        expect(PurchaseReturn::query()->count())->toBe(1)
            ->and($bill->fresh()->outstanding()->minor)->toBe(80000);

        // From the bill page too, with the supplier's own debit note number.
        $this->actingAs($this->w->owner)->post("/purchasing/bills/{$bill->id}/return", [...$payload, 'request_key' => (string) Str::uuid(), 'quantity' => null, 'credit_note_number' => 'DN-77'])
            ->assertRedirect();

        expect(SupplierCreditNote::query()->where('credit_note_number', 'DN-77')->exists())->toBeTrue()
            ->and($this->m->problems())->toBe([]);
    });
});
