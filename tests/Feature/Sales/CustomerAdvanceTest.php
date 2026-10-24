<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyStatement;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\ApplyCustomerAdvances;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\RecordCustomerAdvance;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Actions\RefundCustomerAdvance;
use App\Modules\Sales\Corrections\CustomerAdvanceActionPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceApplicationPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceRefundPolicy;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Support\CustomerAdvanceBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesWorld;

/**
 * ⭐ B11 (24 Oct 2026, Joy's R90): customer advances. Money with no invoice and
 * no order is kept on 2150 for the customer, used on their invoices oldest
 * first, paid back, or moved there from a cancelled order; every document is
 * reversed through the corrections engine in dependency order, and AR, 2150
 * and the statements keep agreeing with the ledger throughout.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $this->invoice = $this->s->invoice($this->s->challan(null, '10'), '850');
});

afterEach(fn () => Carbon::setTestNow());

function advanceOf(SalesWorld $s, string $amount = '5000', string $date = '2026-10-14'): CustomerAdvance
{
    return app(RecordCustomerAdvance::class)->receive($s->customer, $s->w->bank, $date, $amount, $s->w->financeA, 'bKash TX1');
}

/** The customer's 2150 balance, positive = we hold theirs. */
function theirAdvanceOnLedger(SalesWorld $s): int
{
    return -(int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug('customer_advances')->id)
        ->where('party_type', $s->customer->getMorphClass())
        ->where('party_id', $s->customer->id)
        ->sum('amount_minor');
}

function statementAgrees(SalesWorld $s): bool
{
    return CustomerStatement::for($s->customer, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))->isConsistent();
}

it('receives money on account into 2150 for the customer, with a CADV number', function () {
    $advance = advanceOf($this->s);

    $journal = Journal::query()->where('source_type', $advance->getMorphClass())->where('source_id', $advance->id)->sole();

    expect($advance->number)->toStartWith('CADV-')
        ->and($journal->totalDebits()->minor)->toBe(500000)
        ->and(theirAdvanceOnLedger($this->s))->toBe(500000)
        ->and($this->s->balance('cash_at_bank'))->toBe(500000)
        ->and(CustomerAdvanceBook::totalOpen($this->s->customer)->minor)->toBe(500000)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses a courier account, a future date, a closed month and a nonsense amount', function () {
    expect(fn () => app(RecordCustomerAdvance::class)->receive($this->s->customer, $this->s->w->bank, '2026-10-20', '100', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'not happened yet');

    expect(fn () => app(RecordCustomerAdvance::class)->receive($this->s->customer, $this->s->w->bank, '2026-10-14', '1e3', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'in taka');

    $courier = MoneyAccount::create(['business_id' => $this->s->w->business->id, 'name' => 'Pathao', 'type' => 'courier', 'is_active' => true, 'account_id' => $this->s->w->bank->account_id]);
    expect(fn () => app(RecordCustomerAdvance::class)->receive($this->s->customer, $courier, '2026-10-14', '100', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'never by hand');

    $this->s->w->closeThrough('2026-10-14');
    expect(fn () => app(RecordCustomerAdvance::class)->receive($this->s->customer, $this->s->w->bank, '2026-10-14', '100', $this->s->w->financeA))
        ->toThrow(PeriodClosedException::class);

    expect(CustomerAdvance::count())->toBe(0);
});

it('uses advances on an invoice oldest first: the invoice owes less, 2150 down, AR down, statements agree', function () {
    $older = advanceOf($this->s, '3000', '2026-10-13');
    $newer = advanceOf($this->s, '4000', '2026-10-14');

    $proposal = CustomerAdvanceBook::proposal($this->s->customer, $this->invoice->outstanding());
    expect($proposal[0]['advance_id'])->toBe($older->id)
        ->and($proposal[0]['amount']->toTaka())->toBe('3000.00')
        ->and($proposal[1]['amount']->toTaka())->toBe('4000.00');

    $uses = app(ApplyCustomerAdvances::class)->handle($this->invoice, [
        ['advance' => $older, 'amount' => '3000'],
        ['advance' => $newer, 'amount' => '4000'],
    ], '2026-10-15', $this->s->w->financeA);

    expect($uses)->toHaveCount(2)
        ->and($uses[0]->number)->toStartWith('CAAP-')
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('1500.00')
        ->and($older->fresh()->open()->isZero())->toBeTrue()
        ->and(theirAdvanceOnLedger($this->s))->toBe(0)
        ->and($this->s->balance('accounts_receivable'))->toBe(150000)
        ->and(statementAgrees($this->s))->toBeTrue()
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses using more than the advance holds, more than the invoice owes, before the invoice, or twice', function () {
    $advance = advanceOf($this->s, '9000');

    expect(fn () => app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $advance, 'amount' => '8600']], '2026-10-15', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'outstanding');

    expect(fn () => app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $advance, 'amount' => '100']], '2026-10-12', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'invoice date or later');

    $small = advanceOf($this->s, '500');
    expect(fn () => app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $small, 'amount' => '600']], '2026-10-15', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'left to use');

    app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $small, 'amount' => '500']], '2026-10-15', $this->s->w->financeA);

    // The double click: the same use again finds nothing left.
    expect(fn () => app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $small, 'amount' => '500']], '2026-10-15', $this->s->w->financeA))
        ->toThrow(ValidationException::class, 'left to use');

    expect(CustomerAdvanceApplication::count())->toBe(1)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('pays an advance back, capped by what is open', function () {
    $advance = advanceOf($this->s, '2000');

    $refund = app(RefundCustomerAdvance::class)->handle($this->s->customer, $advance, $this->s->w->bank, '2026-10-15', '1500', $this->s->w->owner);

    expect($refund->number)->toStartWith('CARF-')
        ->and($advance->fresh()->open()->toTaka())->toBe('500.00')
        ->and(theirAdvanceOnLedger($this->s))->toBe(50000)
        ->and($this->s->balance('cash_at_bank'))->toBe(50000);

    expect(fn () => app(RefundCustomerAdvance::class)->handle($this->s->customer, $advance, $this->s->w->bank, '2026-10-15', '600', $this->s->w->owner))
        ->toThrow(ValidationException::class, 'left to pay back');
});

it('reverses in dependency order through the engine: the use first, then the advance', function () {
    $advance = advanceOf($this->s, '5000');
    $use = app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $advance, 'amount' => '5000']], '2026-10-15', $this->s->w->financeA)->first();
    $registry = app(DocumentPolicyRegistry::class);

    $codes = collect($registry->for(CustomerAdvanceActionPolicy::TYPE)->availability($advance->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('advance_used');

    // The invoice cannot be credited while the advance pays it.
    $invoiceCodes = collect($registry->for('sales.invoice')->availability($this->invoice->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($invoiceCodes)->toContain('advance_used');

    $result = $this->s->submit($this->s->input(CustomerAdvanceApplicationPolicy::TYPE, $use, DocumentAction::Reverse), $this->s->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($use->fresh()->isReversed())->toBeTrue()
        ->and(PartyAdjustment::query()->whereKey($use->party_adjustment_id)->first()->isReversed())->toBeTrue()
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('8500.00')
        ->and($advance->fresh()->open()->toTaka())->toBe('5000.00')
        ->and(statementAgrees($this->s))->toBeTrue()
        ->and($this->s->reconciliationProblems())->toBe([]);

    $this->s->submit($this->s->input(CustomerAdvanceActionPolicy::TYPE, $advance->fresh(), DocumentAction::Reverse), $this->s->w->financeB);

    expect($advance->fresh()->isReversed())->toBeTrue()
        ->and(theirAdvanceOnLedger($this->s))->toBe(0)
        ->and($this->s->balance('cash_at_bank'))->toBe(0)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('reverses a refund: the money comes back and the advance is open again', function () {
    $advance = advanceOf($this->s, '2000');
    $refund = app(RefundCustomerAdvance::class)->handle($this->s->customer, $advance, $this->s->w->bank, '2026-10-15', '2000', $this->s->w->owner);

    $this->s->submit($this->s->input(CustomerAdvanceRefundPolicy::TYPE, $refund, DocumentAction::Reverse), $this->s->w->financeB);

    expect($refund->fresh()->isReversed())->toBeTrue()
        ->and($advance->fresh()->open()->toTaka())->toBe('2000.00')
        ->and($this->s->balance('cash_at_bank'))->toBe(200000);
});

it('a Manager and a cashier have no advance reversal, and get 403 from the server', function () {
    $advance = advanceOf($this->s);
    $policy = app(DocumentPolicyRegistry::class)->for(CustomerAdvanceActionPolicy::TYPE);

    foreach ([$this->s->w->manager, $this->s->cashier] as $user) {
        expect($policy->actionsFor($advance, $user)->actions)->toBe([]);

        $this->actingAs($user)
            ->postJson('/records/'.CustomerAdvanceActionPolicy::TYPE."/{$advance->id}/reverse/preview", ['reason_text' => 'Wrong amount typed', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
    }
});

it('moves a cancelled order\'s money to the advance: the order holds nothing, AR and 2150 agree, and a reversal gives it back', function () {
    $order = $this->s->order('2');
    $payment = app(RecordOrderPayment::class)->handle($order, $this->s->w->bank, '2026-10-11', '1700', $this->s->w->financeA);
    app(CancelSalesOrder::class)->handle($order->fresh());

    expect($order->fresh()->advanceHeld()->toTaka())->toBe('1700.00');

    // Not while the order is open, and only by those who cancel orders.
    $this->actingAs($this->s->w->financeA)
        ->post("/sales/orders/{$order->id}/to-advance", ['moved_on' => '2026-10-16'])
        ->assertForbidden();

    $this->actingAs($this->s->w->manager)
        ->post("/sales/orders/{$order->id}/to-advance", ['moved_on' => '2026-10-16'])
        ->assertSessionHasNoErrors();

    $moved = CustomerAdvance::query()->where('kind', CustomerAdvance::FROM_ORDER)->sole();

    expect($moved->money()->toTaka())->toBe('1700.00')
        ->and($order->fresh()->advanceHeld()->isZero())->toBeTrue()
        ->and(theirAdvanceOnLedger($this->s))->toBe(170000)
        ->and(statementAgrees($this->s))->toBeTrue()
        ->and($this->s->reconciliationProblems())->toBe([]);

    // It now pays an invoice like any advance.
    app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => $moved, 'amount' => '1700']], '2026-10-16', $this->s->w->financeA);
    expect($this->invoice->fresh()->outstanding()->toTaka())->toBe('6800.00');

    // Moving again finds nothing held.
    expect(fn () => app(RecordCustomerAdvance::class)->fromOrder($order->fresh(), '2026-10-16', $this->s->w->manager))
        ->toThrow(ValidationException::class, 'holds none');

    $use = CustomerAdvanceApplication::sole();
    $this->s->submit($this->s->input(CustomerAdvanceApplicationPolicy::TYPE, $use, DocumentAction::Reverse), $this->s->w->financeB);
    $this->s->submit($this->s->input(CustomerAdvanceActionPolicy::TYPE, $moved->fresh(), DocumentAction::Reverse), $this->s->w->financeB);

    expect($order->fresh()->advanceHeld()->toTaka())->toBe('1700.00')
        ->and(theirAdvanceOnLedger($this->s))->toBe(0)
        ->and(statementAgrees($this->s))->toBeTrue()
        ->and($this->s->reconciliationProblems())->toBe([]);

    unset($payment);
});

it('the receive payment screen: the advance first, the money on the rest, and the extra kept as an advance', function () {
    $advance = advanceOf($this->s, '1000', '2026-10-13');

    $this->actingAs($this->s->w->financeA)->post('/sales/receipts', [
        'received_on' => '2026-10-15',
        'customer_id' => $this->s->customer->id,
        'money_account_id' => $this->s->w->bank->id,
        'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => '7500']],
        'advance_uses' => [['invoice_id' => $this->invoice->id, 'key' => "advance:{$advance->id}", 'amount' => '1000']],
        'keep_as_advance' => '2000',
    ])->assertSessionHasNoErrors();

    $kept = CustomerAdvance::query()->latest('id')->first();

    expect($this->invoice->fresh()->outstanding()->isZero())->toBeTrue()
        ->and($kept->money()->toTaka())->toBe('2000.00')
        ->and($kept->customer_receipt_id)->toBe(CustomerReceipt::sole()->id)
        ->and(theirAdvanceOnLedger($this->s))->toBe(200000)
        ->and($this->s->balance('cash_at_bank'))->toBe(100000 + 750000 + 200000)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('the receive payment screen: money with no invoice at all is an advance', function () {
    $this->actingAs($this->s->w->financeA)->post('/sales/receipts', [
        'received_on' => '2026-10-15',
        'customer_id' => $this->s->customer->id,
        'money_account_id' => $this->s->w->bank->id,
        'allocations' => [],
        'keep_as_advance' => '3000',
    ])->assertSessionHasNoErrors();

    expect(CustomerReceipt::count())->toBe(0)
        ->and(CustomerAdvance::sole()->money()->toTaka())->toBe('3000.00');

    $this->actingAs($this->s->w->financeA)->post('/sales/receipts', [
        'received_on' => '2026-10-15',
        'customer_id' => $this->s->customer->id,
        'money_account_id' => $this->s->w->bank->id,
        'allocations' => [],
    ])->assertSessionHasErrors('allocations');
});

it('the invoice page uses an advance; the advances page lists it with Reverse for Finance', function () {
    $advance = advanceOf($this->s, '1000');

    $this->actingAs($this->s->w->financeA)->post("/sales/invoices/{$this->invoice->id}/advances", [
        'applied_on' => '2026-10-15',
        'advances' => [['key' => "advance:{$advance->id}", 'amount' => '1000']],
    ])->assertSessionHasNoErrors();

    expect($this->invoice->fresh()->outstanding()->toTaka())->toBe('7500.00');

    $this->actingAs($this->s->w->financeB)->get('/sales/advances?customer_id='.$this->s->customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('sales/advances/index')
            ->where('advances.data.0.number', $advance->number)
            ->where('uses.0.invoice.invoice_number', $this->invoice->invoice_number)
            ->where('uses.0.actions.0.action', 'reverse'));

    $this->actingAs($this->s->w->financeB)->get("/sales/invoices/{$this->invoice->id}")
        ->assertInertia(fn ($page) => $page->where('advance.uses.0.number', CustomerAdvanceApplication::sole()->number));

    $this->actingAs($this->s->w->financeB)->get("/sales/customers/{$this->s->customer->id}")
        ->assertInertia(fn ($page) => $page->where('advances.applied', Money::ofTaka('1000')->format()));
});

it('uses the on-account balance a journal put on 2150, and refuses to reverse that journal underneath it', function () {
    $cashHead = Account::bySlug('cash_in_hand');
    $journal = app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-10-14'),
        lines: [
            JournalLineDraft::debit($cashHead->id, Money::ofTaka('1200'), 'Cash', null, $this->s->w->cash),
            JournalLineDraft::credit(Account::bySlug('customer_advances')->id, Money::ofTaka('1200'), 'Advance', $this->s->customer),
        ],
        memo: 'Advance by hand',
    ), $this->s->w->owner);

    $rows = CustomerAdvanceBook::rows($this->s->customer);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['key'])->toBe('on_account')
        ->and($rows[0]['open'])->toBe('1200.00');

    app(ApplyCustomerAdvances::class)->handle($this->invoice, [['advance' => null, 'amount' => '1200']], '2026-10-15', $this->s->w->financeA);

    expect(CustomerAdvanceApplication::sole()->source_note)->toContain($journal->journal_number)
        ->and(CustomerAdvanceBook::onAccount($this->s->customer)->isZero())->toBeTrue();

    expect(fn () => app(ReverseJournal::class)->handle($journal->load('lines'), $this->s->w->owner, null, 'Typed twice'))
        ->toThrow(ValidationException::class, 'Reverse that use or refund first');
});

it('the party statement and card count their advance with us as part of the net', function () {
    $supplier = Supplier::create(['business_id' => $this->s->w->business->id, 'name' => 'Karim Traders', 'code' => 'SUP-K']);
    DB::table('customers')->where('id', $this->s->customer->id)->update(['supplier_id' => $supplier->id]);
    advanceOf($this->s, '1000');

    $statement = PartyStatement::for($supplier, $this->s->customer->fresh(), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect($statement->customerAdvanceClosing->toTaka())->toBe('1000.00')
        ->and($statement->closingNet()->toTaka())->toBe('7500.00')
        ->and($statement->lines->where('side', 'customer_advance'))->toHaveCount(1)
        ->and($statement->consistent())->toBeTrue();
});
