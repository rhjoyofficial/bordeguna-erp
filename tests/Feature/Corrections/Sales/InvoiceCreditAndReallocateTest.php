<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: the sales corrections that are documents of their own
 * (FINAL-PLAN 3.2): a price credit note (goods kept), an extra charge (debit
 * note), and Re-allocate (a receipt's money to another invoice of the same
 * customer, no journal). Each asks for a reason, each keeps AR per customer
 * agreeing with the documents.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->invoice = $this->s->invoice($this->s->challan(null, '10'), '850');
});

afterEach(fn () => Carbon::setTestNow());

it('a price credit note lowers what is owed, with its own CN number, contra revenue, and the invoice unchanged', function () {
    $this->actingAs($this->s->w->financeB)
        ->post(route('sales.invoices.price-credit', $this->invoice), ['credit_date' => '2026-10-15', 'amount' => '500', 'reason_text' => 'Two jars were short-weight'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $note = SalesCreditNote::sole();
    expect($note->kind)->toBe('price')
        ->and($note->credit_note_number)->toBe('CN-2026-27-0001')
        ->and($note->journal_id)->not->toBeNull()
        ->and(PartyAdjustment::query()->whereKey($note->party_adjustment_id)->value('kind'))->toBe('customer_credit_note')
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('8000.00')
        ->and((float) $this->invoice->fresh()->total)->toBe(8500.0)
        ->and($this->s->balance('sales_returns'))->toBe(50000)
        ->and($this->s->balance('accounts_receivable'))->toBe(800000)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // A full credit note cannot follow it.
    $codes = collect(app(DocumentPolicyRegistry::class)->for(SalesInvoiceActionPolicy::TYPE)->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('adjusted');
});

it('a price credit note needs a reason, an open date, and no more than is owed', function () {
    $as = $this->actingAs($this->s->w->financeB);

    $as->post(route('sales.invoices.price-credit', $this->invoice), ['credit_date' => '2026-10-15', 'amount' => '500', 'reason_text' => 'no'])->assertSessionHasErrors('reason_text');
    $as->post(route('sales.invoices.price-credit', $this->invoice), ['credit_date' => '2026-10-15', 'amount' => '9000', 'reason_text' => 'Too much asked'])->assertSessionHasErrors('amount');
    $this->s->w->closeThrough('2026-10-15');
    $as->post(route('sales.invoices.price-credit', $this->invoice), ['credit_date' => '2026-10-15', 'amount' => '100', 'reason_text' => 'Closed month try'])->assertSessionHasErrors('credit_date');

    expect(SalesCreditNote::count())->toBe(0);
});

it('a cashier cannot give a price credit or an extra charge', function () {
    $this->actingAs($this->s->cashier)
        ->post(route('sales.invoices.price-credit', $this->invoice), ['credit_date' => '2026-10-15', 'amount' => '100', 'reason_text' => 'A kind word'])
        ->assertForbidden();
    $this->actingAs($this->s->cashier)
        ->post(route('sales.invoices.extra-charge', $this->invoice), ['charge_date' => '2026-10-15', 'amount' => '100', 'description' => 'Packing', 'reason_text' => 'Left off the bill'])
        ->assertForbidden();
});

it('an extra charge is an invoice of its own on the first one, and the first one is unchanged', function () {
    $this->actingAs($this->s->w->manager)
        ->post(route('sales.invoices.extra-charge', $this->invoice), ['charge_date' => '2026-10-15', 'amount' => '300', 'description' => 'Cold-box packing', 'reason_text' => 'Left off the bill'])
        ->assertSessionHasNoErrors();

    $extra = SalesInvoice::query()->where('debit_note_for_invoice_id', $this->invoice->id)->sole();
    expect((float) $extra->total)->toBe(300.0)
        ->and($extra->lines()->value('description'))->toBe("Extra charge on {$this->invoice->invoice_number}: Cold-box packing")
        ->and((float) $this->invoice->fresh()->total)->toBe(8500.0)
        ->and($this->s->balance('accounts_receivable'))->toBe(880000)
        ->and($this->s->reconciliationProblems())->toBe([]);

    $codes = collect(app(DocumentPolicyRegistry::class)->for(SalesInvoiceActionPolicy::TYPE)->availability($this->invoice->fresh(), $this->s->w->manager, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('extra_charge');
});

it('re-allocates a receipt to another invoice of the same customer, with no journal, and logs it', function () {
    $second = $this->s->invoice($this->s->challan(null, '2', '2026-10-13'), '850', '2026-10-14');
    $receipt = $this->s->receive($this->invoice, '3000');
    $journals = Journal::count();

    $this->actingAs($this->s->cashier)
        ->post(route('sales.receipts.reallocate', $receipt), ['from_invoice_id' => $this->invoice->id, 'to_invoice_id' => $second->id, 'amount' => '1700', 'reason_text' => 'Customer meant the second bill'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($this->invoice->fresh()->outstanding()->toTaka())->toBe('7200.00')
        ->and($second->fresh()->outstanding()->toTaka())->toBe('0.00')
        ->and(Journal::count())->toBe($journals)
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'reallocated')->count())->toBe(1)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses to move more than is on the invoice, or onto another customer\'s invoice', function () {
    $receipt = $this->s->receive($this->invoice, '1000');
    $other = Customer::create(['business_id' => $this->s->w->business->id, 'name' => 'Someone Else']);
    $foreign = SalesInvoice::create(['business_id' => $this->s->w->business->id, 'customer_id' => $other->id, 'invoice_number' => 'INV-X', 'invoice_date' => '2026-10-13', 'subtotal' => 100, 'total' => 100, 'created_by' => $this->s->w->owner->id]);
    $second = $this->s->invoice($this->s->challan(null, '2', '2026-10-13'), '850', '2026-10-14');

    $this->actingAs($this->s->cashier)
        ->post(route('sales.receipts.reallocate', $receipt), ['from_invoice_id' => $this->invoice->id, 'to_invoice_id' => $second->id, 'amount' => '1500', 'reason_text' => 'Moving too much'])
        ->assertSessionHasErrors('amount');
    $this->actingAs($this->s->cashier)
        ->post(route('sales.receipts.reallocate', $receipt), ['from_invoice_id' => $this->invoice->id, 'to_invoice_id' => $foreign->id, 'amount' => '100', 'reason_text' => 'Wrong customer'])
        ->assertSessionHasErrors('to_invoice_id');
});
