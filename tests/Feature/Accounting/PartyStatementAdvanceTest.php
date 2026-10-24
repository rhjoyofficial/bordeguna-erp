<?php

use App\Modules\Accounting\Support\PartyPosition;
use App\Modules\Accounting\Support\PartyStatement;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceRefund;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PartyWorld;

/**
 * ⭐ The party statement counts our advance with the supplier (owner request
 * 20 Oct 2026, the Ayesha case): an invoice of 39,931 and an unused advance of
 * 17,400 is 57,331 in our favour, never "They owe us 39,931". An advance paid
 * adds to the net, a refund takes it off, and an advance used on a bill moves
 * nothing (the bill side and the advance side cancel). The closing figures tie
 * to the ledger: 1140 + 1160 - 2110, each party-tagged.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->p = PartyWorld::make();
    $this->w = $this->p->w;
    // Money in the bank to pay the advance from.
    $this->w->record('100000', '2026-10-01');

    $this->advance = fn (string $amount, string $on) => app(RecordSupplierAdvance::class)->pay(
        supplier: $this->p->supplier, moneyAccount: $this->w->bank, paidOn: $on, amount: $amount, createdBy: $this->w->financeA, reference: 'BRAC TT 42',
    );
    $this->window = fn () => PartyStatement::for($this->p->supplier, $this->p->customer->fresh(), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());
});

afterEach(fn () => Carbon::setTestNow());

it('adds an unused advance to what they owe us: the Ayesha case', function () {
    $this->p->invoice('39931', '2026-10-02');
    ($this->advance)('17400', '2026-10-03');

    $statement = ($this->window)();

    expect($statement->lines->map(fn (array $line) => [$line['side'], $line['kind'], $line['net']->minor])->all())->toBe([
        ['customer', 'invoice', 3_993_100],
        ['advance', 'advance_paid', 5_733_100],
    ])
        ->and($statement->advanceClosing->minor)->toBe(1_740_000)
        ->and($statement->closingNet()->minor)->toBe(5_733_100)
        ->and($statement->consistent())->toBeTrue()
        // The ledger: 1140 + 1160 - 2110 for this party.
        ->and($this->p->balance('accounts_receivable') + $this->p->balance('supplier_advances') + $this->p->balance('accounts_payable'))->toBe(5_733_100);

    $this->actingAs($this->w->financeA)->get(route('accounting.party-statement', ['supplier' => $this->p->supplier->id, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/party-statement/index')
            ->has('lines', 2)
            ->where('lines.1.side', 'advance')
            ->where('lines.1.advance_change', '17,400.00')
            ->where('lines.1.they_owe_change', null)
            ->where('lines.1.we_owe_change', null)
            ->where('lines.1.reference', 'BRAC TT 42')
            ->where('summary.advance_closing', '17,400.00')
            ->where('summary.closing.amount', '57,331.00')
            ->where('summary.closing.side', 'us')
            ->where('summary.consistent', true));

    // The card on the supplier page says the same.
    $position = PartyPosition::of($this->p->supplier, $this->p->customer->fresh());
    expect($position->advance->minor)->toBe(1_740_000)
        ->and($position->net()->minor)->toBe(5_733_100)
        ->and($position->settleable()->minor)->toBe(0);

    $this->actingAs($this->w->financeA)->get(route('purchasing.suppliers.show', $this->p->supplier))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('party.position.advance', '17,400.00')
            ->where('party.position.net', '57,331.00')
            ->where('party.position.net_side', 'us'));
});

it('runs the net through an advance paid, partly used on a bill and partly refunded, and ties to the ledger', function () {
    $this->p->invoice('39931', '2026-10-02');
    $advance = ($this->advance)('17400', '2026-10-03');
    $bill = $this->p->bill('10000', '2026-10-05');
    app(ApplySupplierAdvances::class)->handle($bill, [['advance' => $advance, 'amount' => '4000']], '2026-10-06', $this->w->financeA);
    app(RefundSupplierAdvance::class)->handle($this->p->supplier, $advance->fresh(), $this->w->bank, '2026-10-08', '1000', $this->w->financeA, 'Cheque back');

    $statement = ($this->window)();

    expect($statement->lines->map(fn (array $line) => [$line['side'], $line['kind'], $line['net']->minor])->all())->toBe([
        ['customer', 'invoice', 3_993_100],          // they owe us 39,931
        ['advance', 'advance_paid', 5_733_100],       // and hold 17,400 of ours
        ['supplier', 'bill', 4_733_100],              // we owe them 10,000
        ['supplier', 'adjustment', 5_133_100],        // the advance lowers the bill by 4,000
        ['advance', 'advance_applied', 4_733_100],    // and is used up by the same 4,000
        ['advance', 'advance_refunded', 4_633_100],   // they give 1,000 back
    ])
        ->and($statement->advanceOpening->minor)->toBe(0)
        ->and($statement->advanceClosing->minor)->toBe(1_240_000)
        ->and($statement->supplierSide->closing()->minor)->toBe(600_000)
        ->and($statement->customerSide->closing()->minor)->toBe(3_993_100)
        ->and($statement->closingNet()->minor)->toBe(4_633_100)
        ->and($statement->consistent())->toBeTrue()
        // Each part against its own party-tagged head.
        ->and($this->p->balance('supplier_advances'))->toBe(1_240_000)
        ->and($this->p->balance('accounts_payable'))->toBe(-600_000)
        ->and($this->p->balance('accounts_receivable'))->toBe(3_993_100)
        ->and($this->p->balance('accounts_receivable') + $this->p->balance('supplier_advances') + $this->p->balance('accounts_payable'))
        ->toBe($statement->closingNet()->minor);

    // A later window opens on the same net, advance included.
    $later = PartyStatement::for($this->p->supplier, $this->p->customer->fresh(), Carbon::parse('2026-10-07'), Carbon::parse('2026-10-31')->endOfDay());
    expect($later->advanceOpening->minor)->toBe(1_340_000)
        ->and($later->openingNet()->minor)->toBe(4_733_100)
        ->and($later->closingNet()->minor)->toBe(4_633_100);
});

it('shows a reversed refund as its own line and keeps the net on the ledger', function () {
    $advance = ($this->advance)('5000', '2026-10-03');
    $refund = app(RefundSupplierAdvance::class)->handle($this->p->supplier, $advance, $this->w->bank, '2026-10-04', '2000', $this->w->financeA);
    app(ReverseSupplierAdvanceRefund::class)->handle($refund, $this->w->owner, 'Cheque bounced at the bank');

    $statement = ($this->window)();

    expect($statement->lines->pluck('kind')->all())->toBe(['advance_paid', 'advance_refunded', 'advance_refunded_reversed'])
        ->and($statement->advanceClosing->minor)->toBe(500_000)
        ->and($statement->closingNet()->minor)->toBe(500_000)
        ->and($statement->consistent())->toBeTrue();
});
