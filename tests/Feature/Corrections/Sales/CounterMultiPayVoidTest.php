<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesWorld;

/**
 * ⭐ B12 (25 Oct 2026): a counter bill paid by several lines is voided whole.
 * Void sale finds every receipt the sale wrote (CounterSaleBundle) and takes
 * them all back together with the invoice and the challan, each money account
 * by exactly what it took; the unpaid rest simply stops being owed.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->policy = app(DocumentPolicyRegistry::class)->for(SalesInvoiceActionPolicy::TYPE);

    $this->sale = fn () => app(RecordCounterSale::class)->handle(
        customer: $this->s->customer,
        warehouse: $this->s->warehouse,
        lines: [['product' => $this->s->honey, 'quantity' => '3', 'unitPrice' => '850']],
        createdBy: $this->s->cashier,
        payments: [
            ['moneyAccount' => $this->s->w->cash, 'amount' => '1000'],
            ['moneyAccount' => $this->s->w->bank, 'amount' => '700'],
        ],
    );
});

afterEach(fn () => Carbon::setTestNow());

it('Void sale reverses every payment line, the invoice and the challan together', function () {
    DB::table('pos_shifts')->insert(['business_id' => $this->s->w->business->id, 'money_account_id' => $this->s->w->cash->id, 'opened_by' => $this->s->cashier->id, 'opened_at' => now()->subHour(), 'opening_float' => 0, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $onHand = $this->s->onHand();
    $sale = ($this->sale)();

    // 3 x 850 = 2,550: 1,000 cash + 700 bank, 850 left on account.
    expect($sale->receipts)->toHaveCount(2)
        ->and($sale->invoice->fresh()->outstanding()->toTaka())->toBe('850.00')
        ->and($this->s->balance('accounts_receivable'))->toBe(85000);

    // The cash line is the till of her open shift, so the cashier may void it.
    expect(collect($this->policy->actionsFor($sale->invoice, $this->s->cashier)->actions)->pluck('action')->map->value->all())->toBe(['reverse']);

    $result = $this->s->submit($this->s->input(SalesInvoiceActionPolicy::TYPE, $sale->invoice, DocumentAction::Reverse), $this->s->cashier);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(collect($sale->receipts)->every(fn ($r) => $r->fresh()->isReversed()))->toBeTrue()
        ->and($sale->invoice->fresh()->isReversed())->toBeTrue()
        ->and($sale->delivery->fresh()->isReversed())->toBeTrue()
        ->and($this->s->onHand())->toBe($onHand)
        ->and($this->s->balance('cash_in_hand'))->toBe(0)
        ->and($this->s->balance('cash_at_bank'))->toBe(0)
        ->and($this->s->balance('accounts_receivable'))->toBe(0)
        // Two receipts, the invoice and the challan: four reversals.
        ->and(Journal::query()->whereNotNull('reverses_journal_id')->count())->toBe(4)
        ->and($this->s->reconciliationProblems())->toBe([]);
});
