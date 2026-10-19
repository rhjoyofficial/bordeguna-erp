<?php

use App\Modules\Accounting\Actions\LinkSupplierCustomer;
use App\Modules\Accounting\Actions\SetOffs;
use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PartyWorld;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: a sales invoice (FINAL-PLAN 3.2 "Sales invoice" and
 * "Counter sale"). The issued invoice never changes: a full credit note with
 * its own CN number takes it back, Correct = credit note + the right invoice,
 * and a counter sale is voided whole (receipt, invoice and challan).
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->challan = $this->s->challan(null, '10');
    $this->invoice = $this->s->invoice($this->challan, '850');
    $this->policy = app(DocumentPolicyRegistry::class)->for(SalesInvoiceActionPolicy::TYPE);
});

afterEach(fn () => Carbon::setTestNow());

function invoiceCorrection(SalesInvoice $invoice, array $line = [], array $header = []): array
{
    $invoice->loadMissing('lines');

    return array_replace([
        'invoice_date' => $invoice->invoice_date->toDateString(),
        'due_date' => $invoice->due_date?->toDateString(),
        'invoice_discount' => (string) (float) $invoice->invoice_discount,
        'note' => $invoice->note,
        'lines' => $invoice->lines->whereNull('billed_delivery_for_order_id')->map(fn ($l) => array_replace([
            'invoice_line_id' => $l->id,
            'quantity' => (string) (float) $l->quantity,
            'unit_price' => (string) (float) $l->unit_price,
            'discount' => (string) (float) $l->line_discount,
        ], $line))->values()->all(),
    ], $header);
}

it('a credit note takes the invoice back whole with its own CN number; the invoice itself is unchanged', function () {
    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $this->invoice, DocumentAction::Reverse), $this->s->w->financeB);

    $note = SalesCreditNote::sole();
    $invoice = $this->invoice->fresh();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($note->credit_note_number)->toBe('CN-2026-27-0001')
        ->and($note->kind)->toBe('full')
        ->and($note->journal_id)->toBe($result->reversalJournal->id)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-13')
        ->and($invoice->isReversed())->toBeTrue()
        ->and((float) $invoice->total)->toBe(8500.0)
        ->and($invoice->outstanding()->toTaka())->toBe('0.00')
        ->and((float) $this->challan->lines()->first()->quantity_invoiced)->toBe(0.0)
        ->and($this->s->balance('accounts_receivable'))->toBe(0)
        ->and($this->s->balance('cost_of_goods_sold'))->toBe(0)
        ->and($this->s->balance('goods_delivered_not_invoiced'))->toBe(500000)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // The challan bills again, at the same cost.
    $again = $this->s->invoice($this->challan->fresh(), '800', '2026-10-16');
    expect((float) $again->costReleased()->toTaka())->toBe(5000.0)
        ->and($this->s->balance('goods_delivered_not_invoiced'))->toBe(0)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('Correct: the right invoice replaces it in one step and the payment moves to it', function () {
    $receipt = $this->s->receive($this->invoice, '5000');
    // The Owner: a Manager's per-bill cap (3%) would refuse Tk 50 off a jar.
    $this->actingAs($this->s->w->owner);

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $this->invoice, DocumentAction::Correct, invoiceCorrection($this->invoice, ['unit_price' => '800'])), $this->s->w->owner);

    $successor = $result->successor;
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and((float) $successor->total)->toBe(8000.0)
        ->and($successor->invoice_number)->not->toBe($this->invoice->invoice_number)
        ->and($successor->outstanding()->toTaka())->toBe('3000.00')
        ->and($receipt->fresh()->allocations()->value('sales_invoice_id'))->toBe($successor->id)
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('0.00')
        ->and(CorrectionLink::sole()->successor_number)->toBe($successor->invoice_number)
        ->and($this->s->balance('accounts_receivable'))->toBe(300000)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('Correct by Finance may change the date but not the price (the override permission)', function () {
    $this->actingAs($this->s->w->financeB);

    expect(fn () => $this->s->preview($this->s->input(SalesInvoiceActionPolicy::TYPE, $this->invoice, DocumentAction::Correct, invoiceCorrection($this->invoice, ['unit_price' => '700'])), $this->s->w->financeB))
        ->toThrow(ValidationException::class, 'price-override');

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $this->invoice, DocumentAction::Correct, invoiceCorrection($this->invoice, [], ['invoice_date' => '2026-10-14'])), $this->s->w->financeB);
    expect($result->successor->invoice_date->toDateString())->toBe('2026-10-14');
});

it('a credit note is refused while a typed payment is on it, but Correct moves it', function () {
    $this->s->receive($this->invoice, '1000');

    $codes = collect($this->policy->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('paid');

    $codes = collect($this->policy->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Correct)->blockers)->pluck('code');
    expect($codes)->not->toContain('paid');
});

it('refuses to credit an invoice a set-off clears until the set-off is reversed', function () {
    (new VoucherWorkflowSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    $p = PartyWorld::make($this->s->w);
    $supplier = Supplier::create(['business_id' => $this->s->w->business->id, 'code' => 'KARIM-S', 'name' => 'Karim Traders', 'phone' => '01700000000', 'address' => 'Dhaka']);
    app(LinkSupplierCustomer::class)->link($supplier, $this->s->customer, $this->s->w->financeA);
    $bill = $p->bill('2000', '2026-10-01', $supplier);
    $setOff = app(SetOffs::class)->raise($supplier, $this->s->customer->fresh(), '2026-10-15', [$this->invoice->id => '2000'], [$bill->id => '2000'], 'Netted with Karim', $this->s->w->financeA);
    $p->approve($setOff);

    $blocker = collect($this->policy->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->firstWhere('code', 'set_off_depends');
    expect($blocker->message)->toContain("set-off {$setOff->fresh()->number}");

    $p->reverse($setOff->fresh(), $this->s->w->financeB);
    expect($this->policy->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->allowed)->toBeTrue();
});

it('Correct on a prepaid order: the order money pays the new invoice', function () {
    $order = $this->s->order('4', '850');
    app(RecordCustomerReceipt::class)->handle(customer: $this->s->customer, moneyAccount: $this->s->w->bank, receivedOn: '2026-10-10', allocations: [], createdBy: $this->s->w->financeA, forOrder: $order, heldForOrder: '3400');
    $invoice = $this->s->invoice($this->s->challan($order, '4'), '850');
    $this->actingAs($this->s->w->owner);

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $invoice, DocumentAction::Correct, invoiceCorrection($invoice, [], ['invoice_date' => '2026-10-15'])), $this->s->w->owner);

    expect($result->successor->outstanding()->toTaka())->toBe('0.00')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00')
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('above Tk 20,000 a credit note needs a second person', function () {
    $big = $this->s->invoice($this->s->challan(null, '30'), '850');

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $big, DocumentAction::Reverse), $this->s->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approval_route)->toBe(ApprovalRoute::SecondPerson)
        ->and(SalesCreditNote::count())->toBe(0);
});

it('a cashier has no invoice credit; a Manager and Finance do', function () {
    expect($this->policy->actionsFor($this->invoice, $this->s->cashier)->actions)->toBe([])
        ->and(collect($this->policy->actionsFor($this->invoice, $this->s->w->manager)->actions)->pluck('action')->map->value->all())->toBe(['correct', 'reverse']);

    $this->actingAs($this->s->cashier)
        ->postJson('/records/'.SalesInvoiceActionPolicy::TYPE."/{$this->invoice->id}/reverse/preview", ['reason_text' => 'Wrong customer billed', 'idempotency_key' => (string) Str::uuid()])
        ->assertForbidden();
});

it('a closed month: the Owner gives the credit note on the invoice date without unlocking the month', function () {
    $this->s->w->closeThrough('2026-10-13');

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $this->invoice, DocumentAction::Reverse), $this->s->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->period_exception_id)->not->toBeNull()
        ->and((bool) AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

// ─── Void sale at the counter ──────────────────────────────────────────────

it('Void sale: the cashier voids her own sale while her shift is open; receipt, invoice and challan go together', function () {
    DB::table('pos_shifts')->insert(['business_id' => $this->s->w->business->id, 'money_account_id' => $this->s->w->cash->id, 'opened_by' => $this->s->cashier->id, 'opened_at' => now()->subHour(), 'opening_float' => 0, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $sale = $this->s->counterSale('2');
    $onHand = $this->s->onHand();

    $actions = $this->policy->actionsFor($sale->invoice, $this->s->cashier)->actions;
    expect(collect($actions)->pluck('action')->map->value->all())->toBe(['reverse']);

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $sale->invoice, DocumentAction::Reverse), $this->s->cashier);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($sale->receipt->fresh()->isReversed())->toBeTrue()
        ->and($sale->invoice->fresh()->isReversed())->toBeTrue()
        ->and($sale->delivery->fresh()->isReversed())->toBeTrue()
        ->and($this->s->onHand())->toBe($onHand + 2)
        ->and($this->s->balance('cash_in_hand'))->toBe(0)
        ->and(Journal::query()->whereNotNull('reverses_journal_id')->count())->toBe(3)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('Void sale after the shift closed, or on somebody else\'s sale, is a Manager\'s', function () {
    $sale = $this->s->counterSale('1');

    expect($this->policy->actionsFor($sale->invoice, $this->s->cashier)->actions)->toBe([])
        ->and(collect($this->policy->actionsFor($sale->invoice, $this->s->w->manager)->actions)->pluck('action')->map->value->all())->toBe(['reverse']);

    $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $sale->invoice, DocumentAction::Reverse), $this->s->w->manager);
    expect($sale->delivery->fresh()->isReversed())->toBeTrue();
});

it('a counter sale in a closed month is not voided in one step: the Owner gives a credit note after reversing the receipt', function () {
    $sale = $this->s->counterSale('1', at: '2026-10-05 10:00:00');
    $this->s->w->closeThrough('2026-10-10');

    $owner = $this->policy->actionsFor($sale->invoice->fresh(), $this->s->w->owner);
    expect(collect($owner->actions)->pluck('action')->map->value->all())->toBe(['correct', 'reverse'])
        ->and(collect($owner->actions[1]->blockers)->pluck('code')->all())->toContain('paid');
});
