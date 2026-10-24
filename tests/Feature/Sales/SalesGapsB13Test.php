<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Sales\Actions\BookConsignment;
use App\Modules\Sales\Actions\Corrections\CreditSalesInvoice;
use App\Modules\Sales\Actions\ImportCourierStatement;
use App\Modules\Sales\Actions\PostCourierStatement;
use App\Modules\Sales\Actions\RecordConsignmentOutcome;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Corrections\CourierStatementActionPolicy;
use App\Modules\Sales\Corrections\SalesReturnActionPolicy;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SalesWorld;

/**
 * ⭐ B13 (24 Oct 2026, Joy's R80, R100, R101; gaps S4, S5, S21): edit a
 * quotation before it is accepted (a sent one is revised, the sent copy kept),
 * a Terms box and an internal note that never prints; a credit note number on
 * every sales return and Reverse of a return through the engine; Reverse of a
 * posted courier payout, after which the same file imports again.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

function quoteInput(SalesWorld $s, array $changes = []): array
{
    return array_replace([
        'customer_id' => $s->customer->id,
        'valid_until' => '2026-10-31',
        'terms' => 'Delivery within 3 days.',
        'note' => 'Margin is thin, do not discount further.',
        'lines' => [['product_id' => $s->honey->id, 'quantity' => '10', 'unit_price' => '800']],
    ], $changes);
}

// ── Quotations ────────────────────────────────────────────────────────────

it('edits a draft quotation in place, keeps Terms for the customer, and never prints the internal note', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/quotations', quoteInput($this->s))->assertSessionHasNoErrors();
    $quote = DB::table('quotations')->sole();

    $this->actingAs($this->s->w->manager)
        ->put("/sales/quotations/{$quote->id}", quoteInput($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'quantity' => '12', 'unit_price' => '790']]]))
        ->assertSessionHasNoErrors();

    $line = DB::table('quotation_lines')->sole();
    expect(DB::table('quotations')->count())->toBe(1)
        ->and((float) $line->quantity)->toBe(12.0)
        ->and((float) $line->unit_price)->toBe(790.0);

    $this->actingAs($this->s->w->manager)->get("/sales/quotations/{$quote->id}/print")
        ->assertInertia(fn ($page) => $page->where('quotation.terms', 'Delivery within 3 days.')->missing('quotation.note'));
});

it('editing a sent quotation keeps the sent copy, marked revised, and saves a draft R1', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/quotations', quoteInput($this->s));
    $quote = DB::table('quotations')->sole();
    $this->actingAs($this->s->w->manager)->post("/sales/quotations/{$quote->id}/send");

    $this->actingAs($this->s->w->manager)
        ->put("/sales/quotations/{$quote->id}", quoteInput($this->s, ['valid_until' => '2026-11-05']))
        ->assertSessionHasNoErrors();

    $old = DB::table('quotations')->where('id', $quote->id)->first();
    $revision = DB::table('quotations')->where('revision_of_id', $quote->id)->sole();

    expect($old->status)->toBe('revised')
        ->and($revision->status)->toBe('draft')
        ->and($revision->quotation_number)->toBe($quote->quotation_number.'-R1')
        ->and(DB::table('quotation_lines')->where('quotation_id', $quote->id)->count())->toBe(1);

    // The revised copy can no longer be edited, sent or accepted.
    $this->actingAs($this->s->w->manager)
        ->put("/sales/quotations/{$quote->id}", quoteInput($this->s))
        ->assertSessionHasErrors('quotation');
    $this->actingAs($this->s->w->manager)->post("/sales/quotations/{$quote->id}/accept")->assertSessionHasErrors('quotation');
});

it('deletes a draft quotation only, and the edit keeps the price-override rule', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/quotations', quoteInput($this->s));
    $quote = DB::table('quotations')->sole();

    // A cashier may record orders but may not type a price.
    $this->actingAs($this->s->cashier)
        ->put("/sales/quotations/{$quote->id}", quoteInput($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'quantity' => '1', 'unit_price' => '1']]]))
        ->assertSessionHasErrors();

    $this->actingAs($this->s->w->manager)->post("/sales/quotations/{$quote->id}/send");
    $this->actingAs($this->s->w->manager)->delete("/sales/quotations/{$quote->id}")->assertSessionHasErrors('quotation');

    $this->actingAs($this->s->w->manager)->post('/sales/quotations', quoteInput($this->s));
    $draft = DB::table('quotations')->where('status', 'draft')->sole();
    $this->actingAs($this->s->w->manager)->delete("/sales/quotations/{$draft->id}")->assertSessionHasNoErrors();

    expect(DB::table('quotations')->where('id', $draft->id)->exists())->toBeFalse()
        ->and(DB::table('quotation_lines')->where('quotation_id', $draft->id)->exists())->toBeFalse();
});

// ── Sales returns ─────────────────────────────────────────────────────────

function returnOf(SalesWorld $s, $delivery, string $toStock = '2', string $writeOff = '0', string $on = '2026-10-15'): SalesReturn
{
    return app(RecordSalesReturn::class)->handle(
        $delivery,
        [['deliveryLine' => $delivery->lines()->first(), 'quantityToStock' => $toStock, 'quantityWrittenOff' => $writeOff]],
        $s->w->owner,
        $on,
    );
}

it('gives every return that credits the customer a number in the shared CN series', function () {
    $delivery = $this->s->challan(null, '10');
    $this->s->invoice($delivery);

    $first = returnOf($this->s, $delivery, '1');
    $second = returnOf($this->s, $delivery, '1');

    expect($first->credit_note_number)->toStartWith('CN-')
        ->and($second->credit_note_number)->not->toBe($first->credit_note_number)
        ->and(app(CreditSalesInvoice::class)->nextNumber($this->s->w->business->id, Carbon::parse('2026-10-16')))
        ->toBe(substr($second->credit_note_number, 0, -4).str_pad((string) ((int) substr($second->credit_note_number, -4) + 1), 4, '0', STR_PAD_LEFT));
});

it('reverses a return: the goods leave the same batch again, the invoice owes again, every sum nets', function () {
    $delivery = $this->s->challan(null, '10');
    $invoice = $this->s->invoice($delivery);
    $onHand = $this->s->onHand();
    $return = returnOf($this->s, $delivery, '2', '1');

    expect($this->s->onHand())->toBe($onHand + 2)
        ->and($invoice->fresh()->outstanding()->toTaka())->toBe('5950.00');

    $result = $this->s->submit($this->s->input(SalesReturnActionPolicy::TYPE, $return, DocumentAction::Reverse), $this->s->w->manager);

    $mirror = SalesReturn::query()->where('reverses_return_id', $return->id)->sole();
    $line = $delivery->lines()->first()->fresh();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($return->fresh()->isReversed())->toBeTrue()
        ->and((float) $mirror->credit_total)->toBe(-(float) $return->credit_total)
        ->and($this->s->onHand())->toBe($onHand)
        ->and((float) $line->quantity_returned)->toBe(0.0)
        ->and($invoice->fresh()->outstanding()->toTaka())->toBe('8500.00')
        ->and(CustomerStatement::for($this->s->customer, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))->isConsistent())->toBeTrue()
        ->and($this->s->reconciliationProblems())->toBe([]);

    // The challan line can be returned again in full.
    $again = returnOf($this->s, $delivery->fresh(), '3', '0', '2026-10-16');
    expect((float) $again->lines()->sole()->quantity)->toBe(3.0)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // A mirror offers nothing, and the original is reversed once only.
    $policy = app(DocumentPolicyRegistry::class)->for(SalesReturnActionPolicy::TYPE);
    expect($policy->actionsFor($mirror, $this->s->w->owner)->actions)->toBe([])
        ->and(collect($policy->availability($return->fresh(), $this->s->w->owner, DocumentAction::Reverse)->blockers)->pluck('code'))->toContain('already_reversed');
});

it('refuses to reverse a return whose goods were sold since', function () {
    $delivery = $this->s->challan(null, '100');
    $this->s->invoice($delivery);
    $return = returnOf($this->s, $delivery, '2');
    $policy = app(DocumentPolicyRegistry::class)->for(SalesReturnActionPolicy::TYPE);

    // Everything in the warehouse leaves, the returned jars with it.
    app(RecordStockOut::class)->handle($this->s->honey, $this->s->warehouse, (string) $this->s->onHand(), $this->s->w->owner, occurredAt: '2026-10-16 10:00:00');
    expect(collect($policy->availability($return->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code'))->toContain('stock_used');
});

it('refuses a reversal once the return was refunded, and a Staff user has no Reverse', function () {
    $delivery = $this->s->challan(null, '10');
    $this->s->invoice($delivery);
    $this->s->receive(SalesInvoice::sole(), '8500');
    $return = returnOf($this->s, $delivery, '1');
    app(RecordCustomerRefund::class)->handle($return, $this->s->w->cash, '850', $this->s->w->owner, '2026-10-15');
    $policy = app(DocumentPolicyRegistry::class)->for(SalesReturnActionPolicy::TYPE);

    expect(collect($policy->availability($return->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code'))->toContain('refunded')
        ->and($policy->actionsFor($return, $this->s->cashier)->actions)->toBe([]);

    $this->actingAs($this->s->cashier)
        ->postJson('/records/'.SalesReturnActionPolicy::TYPE."/{$return->id}/reverse/preview", ['reason_text' => 'Wrong return typed', 'idempotency_key' => (string) Str::uuid()])
        ->assertForbidden();
});

// ── Courier payouts ───────────────────────────────────────────────────────

it('reverses a posted courier payout: the journal mirrors, the parcels are free, and the same file imports again', function () {
    Storage::fake('local');
    $courier = MoneyAccount::create([
        'business_id' => $this->s->w->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    ProductPrice::create(['business_id' => $this->s->w->business->id, 'product_id' => $this->s->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    $delivery = $this->s->challan(null, '1', '2026-10-03');
    $consignment = app(BookConsignment::class)->handle($delivery, $courier, 'CB-1001', '850', $this->s->w->owner);
    app(RecordConsignmentOutcome::class)->handle($consignment, ConsignmentStatus::Delivered, '2026-10-04', '850', [], $this->s->w->owner);

    $csv = implode("\n", [
        'Consignment_id,merchant_order_id,Recipient Name,Recipient Phone,Collectable Amount,Collected Amount,COD_fee,Delivery_fee,Additional_charge,Discount,Total Fee,Compensation,Adjustment,Billing_amount,Weight,Delivery_status,Delivered_by_hub_name,Created_date,Delivered_date,Invoiced_date,Invoice_number,Payout_method,Payment_status,Store_name',
        'CB-1001,,Rahima Test,01799999999,850.00,850.00,0.00,100.00,0.00,0.00,100.00,0.00,0.00,750.00,0.70,delivered,Test Hub,2026-10-04,2026-10-04,2026-10-05,ITEST0001,bank,paid,Test Store',
    ])."\n";
    $import = fn () => app(ImportCourierStatement::class)->handle($courier, null, '2026-10-05', $csv, 'carrybee.csv', $this->s->w->owner);

    $statement = $import();
    $statement = app(PostCourierStatement::class)->handle($statement, $this->s->w->bank, '750', '2026-10-06', $this->s->w->owner);
    $bank = $this->s->balance('cash_at_bank');

    expect(CourierParcelClaim::count())->toBe(1);

    $this->s->submit($this->s->input(CourierStatementActionPolicy::TYPE, $statement, DocumentAction::Reverse), $this->s->w->manager);

    $statement = $statement->fresh();
    expect($statement->status)->toBe(CourierStatement::REVERSED)
        ->and($statement->live_marker)->toBeNull()
        ->and(CourierParcelClaim::count())->toBe(0)
        ->and($this->s->balance('cash_at_bank'))->toBe($bank - 75000)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // The same file and invoice id import and post again.
    $again = $import();
    app(PostCourierStatement::class)->handle($again, $this->s->w->bank, '750', '2026-10-06', $this->s->w->owner);

    expect($again->fresh()->status)->toBe(CourierStatement::POSTED)
        ->and($this->s->balance('cash_at_bank'))->toBe($bank)
        ->and(CourierParcelClaim::count())->toBe(1);

    // A draft offers nothing; Finance may reverse, a cashier may not.
    $policy = app(DocumentPolicyRegistry::class)->for(CourierStatementActionPolicy::TYPE);
    expect($policy->actionsFor($again->fresh(), $this->s->cashier)->actions)->toBe([]);
});
