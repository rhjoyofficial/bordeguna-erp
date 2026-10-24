<?php

use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use App\Modules\Foundation\Corrections\RecordHistory;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Sales\Corrections\ExtraChargePolicy;
use App\Modules\Sales\Corrections\PriceCreditPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): a price credit and an extra charge
 * on an issued sales invoice go through the corrections engine: a preview,
 * the Tk 20,000 second person on the new document's amount, the typed date's
 * month (closed = the Owner), and the invoice stays live for more of them.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->invoice = $this->s->invoice($this->s->challan(null, '40'), '850');
    $this->credit = fn (string $amount, string $on = '2026-10-15') => $this->s->input(PriceCreditPolicy::TYPE, $this->invoice, DocumentAction::Correct, ['credit_date' => $on, 'amount' => $amount], 'Short-weight jars');
});

afterEach(fn () => Carbon::setTestNow());

it('previews a price credit with its date, its effect and what stays, and issues it', function () {
    $preview = $this->s->preview(($this->credit)('500'), $this->s->w->financeB);

    expect($preview->dates->reversalDate->toDateString())->toBe('2026-10-15')
        ->and($preview->approval)->toBe(ApprovalRoute::None)
        ->and(collect($preview->effects)->pluck('sentence')->implode(' '))->toContain('price credit note of Tk 500')
        ->and($preview->stays)->toBe(["{$this->invoice->invoice_number} stays exactly as it was issued."]);

    $this->s->submit(($this->credit)('500'), $this->s->w->financeB);
    // The invoice stays live: a second one is fine.
    $this->s->submit(($this->credit)('200'), $this->s->w->financeB);

    expect(SalesCreditNote::count())->toBe(2)
        ->and($this->invoice->fresh()->isReversed())->toBeFalse()
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('33300.00')
        ->and(CorrectionLink::query()->whereNull('live_key')->count())->toBe(2)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // The invoice's own Credit note and Correct are still offered, and its History says what was issued.
    $codes = collect(app(DocumentPolicyRegistry::class)->for(SalesInvoiceActionPolicy::TYPE)->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->not->toContain('already_corrected')->not->toContain('already_reversed');
    expect(collect(RecordHistory::for($this->invoice->fresh(), $this->s->w->owner)['entries'])->pluck('kind'))->toContain('issued');
});

it('a stale dialog cannot issue twice, and a price credit above Tk 20,000 goes to a second person', function () {
    // Two dialogs opened on the same state: the second is refused as stale.
    $first = ($this->credit)('100');
    $second = ($this->credit)('100');
    $tokenTwo = $this->s->preview($second, $this->s->w->financeB)->previewToken;
    $this->s->submit($first, $this->s->w->financeB);

    expect(fn () => app(CorrectionService::class)->submit($second, $this->s->w->financeB, $tokenTwo))
        ->toThrow(StaleCorrectionException::class);
    expect(SalesCreditNote::count())->toBe(1);

    $result = $this->s->submit(($this->credit)('21000'), $this->s->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and(CorrectionRequest::sole()->approval_route)->toBe(ApprovalRoute::SecondPerson)
        ->and(SalesCreditNote::count())->toBe(1);
});

it('dates by the typed day: a later credit on a closed-month invoice is ordinary; a closed day is the Owner\'s', function () {
    $september = $this->s->invoice($this->s->challan(null, '5', '2026-10-03'), '850', '2026-10-04');
    $this->s->w->closeThrough('2026-10-05');
    $input = fn (string $on) => $this->s->input(PriceCreditPolicy::TYPE, $september, DocumentAction::Correct, ['credit_date' => $on, 'amount' => '100'], 'Price agreed lower');

    $this->s->submit($input('2026-10-15'), $this->s->w->financeB);
    expect(SalesCreditNote::query()->where('sales_invoice_id', $september->id)->count())->toBe(1);

    $preview = $this->s->preview($input('2026-10-05'), $this->s->w->financeB);
    expect($preview->approval)->toBe(ApprovalRoute::OwnerClosedPeriod);

    $this->s->submit($input('2026-10-05'), $this->s->w->owner);
    expect(SalesCreditNote::query()->where('sales_invoice_id', $september->id)->count())->toBe(2)
        ->and(ClosedPeriodException::query()->withoutGlobalScopes()->sole()->status)->toBe(ClosedPeriodException::USED)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('raises an extra charge through the engine, greys it on an extra charge itself, and drops the old route', function () {
    $this->s->submit($this->s->input(ExtraChargePolicy::TYPE, $this->invoice, DocumentAction::Correct, ['charge_date' => '2026-10-15', 'amount' => '300', 'description' => 'Cold-box packing'], 'Left off the bill'), $this->s->w->manager);

    $extra = SalesInvoice::query()->where('debit_note_for_invoice_id', $this->invoice->id)->sole();
    $blocked = app(ExtraChargePolicy::class)->availability($extra, $this->s->w->manager, DocumentAction::Correct);

    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->blockers[0]->message)->toContain('itself an extra charge')
        ->and($this->s->reconciliationProblems())->toBe([]);

    $this->actingAs($this->s->w->manager)
        ->post("/sales/invoices/{$this->invoice->id}/extra-charge", ['charge_date' => '2026-10-15', 'amount' => '1', 'description' => 'x', 'reason_text' => 'Old route try'])
        ->assertStatus(405);
});
