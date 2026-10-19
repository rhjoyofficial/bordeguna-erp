<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Purchasing\Corrections\SupplierPaymentActionPolicy;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): supplier payments in the
 * corrections process (FINAL-PLAN 3.3, 4.2): Correct (date, amount, account,
 * which bills) and Reverse with a reason and a date choice, both refused while
 * bank-matched; AP per supplier and the money accounts tie after each.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    $this->billA = $this->d->bill($this->d->order([[$this->d->salt, '50', '20.00']]), null, 'INV-A');
    $this->billB = $this->d->bill($this->d->order([[$this->d->sugar, '40', '10.00']]), null, 'INV-B');
    // Tk 1,000 from BRAC on 16 Oct, to bill A.
    $this->payment = $this->d->pay($this->billA, '1000');
    $this->bankBalance = fn () => $this->w->balance('11201');
});

afterEach(fn () => Carbon::setTestNow());

it('corrects the date, the amount, the account and the bills: reversed on its own date, paid again, the books tie', function () {
    $bankBefore = ($this->bankBalance)();

    $result = $this->m->correct($this->payment, $this->w->financeB, $this->m->paymentForm($this->payment, [
        'paid_on' => '2026-10-15',
        'money_account_id' => $this->w->cash->id,
        'allocations' => [['supplier_bill_id' => $this->billA->id, 'amount' => '600'], ['supplier_bill_id' => $this->billB->id, 'amount' => '300']],
    ]));

    $successor = $result->successor;

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-16')
        ->and($this->payment->fresh()->isReversed())->toBeTrue()
        ->and($this->payment->fresh()->allocations()->count())->toBe(0)
        ->and($successor->paid_on->toDateString())->toBe('2026-10-15')
        ->and((int) $successor->money_account_id)->toBe($this->w->cash->id)
        ->and((float) $successor->amount)->toBe(900.0)
        ->and($this->billA->fresh()->outstanding()->minor)->toBe(40000)
        ->and($this->billB->fresh()->outstanding()->minor)->toBe(10000)
        // BRAC got its Tk 1,000 back; Cash paid Tk 900.
        ->and(($this->bankBalance)() - $bankBefore)->toBe(100000)
        ->and($this->w->balance('11101'))->toBe(-90000)
        ->and($this->m->problems())->toBe([]);
});

it('reverses with a reason, on its own date by default or today when chosen; the bill is owed again', function () {
    $result = $this->m->reverse($this->payment, $this->w->financeB);

    expect($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-16')
        ->and($this->payment->fresh()->reversal_reason)->toBe('Typed it wrong on the screen')
        ->and($this->billA->fresh()->outstanding()->minor)->toBe(100000)
        ->and($this->m->problems())->toBe([]);

    $second = $this->d->pay($this->billB, '400');
    $today = $this->m->reverse($second, $this->w->financeB, ReverseDateChoice::Today);

    expect($today->reversalJournal->journal_date->toDateString())->toBe('2026-10-17')
        ->and($this->m->problems())->toBe([]);
});

it('the reason is checked on the server: a reverse sent without one is refused', function () {
    $base = '/records/'.SupplierPaymentActionPolicy::TYPE."/{$this->payment->id}";

    $this->actingAs($this->w->financeB)
        ->postJson("{$base}/reverse/preview", ['reason_text' => 'no', 'idempotency_key' => (string) Str::uuid()])
        ->assertUnprocessable();

    expect($this->payment->fresh()->isReversed())->toBeFalse();
});

it('refuses Correct and Reverse while the money line is matched on a bank statement; unmatching lets it through', function () {
    $journal = Journal::find($this->m->journalOf($this->payment));
    $bankLine = DB::table('journal_lines')->where('journal_id', $journal->id)->where('amount_minor', '<', 0)->value('id');
    $import = DB::table('bank_statement_imports')->insertGetId(['business_id' => $this->w->business->id, 'money_account_id' => $this->w->bank->id, 'filename' => 'brac.csv', 'created_at' => now(), 'updated_at' => now()]);
    $statement = DB::table('bank_statement_lines')->insertGetId([
        'business_id' => $this->w->business->id, 'import_id' => $import, 'money_account_id' => $this->w->bank->id,
        'txn_date' => '2026-10-16', 'amount' => '-1000', 'match_status' => 'matched', 'matched_journal_line_id' => $bankLine,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $policy = app(SupplierPaymentActionPolicy::class);

    foreach ([DocumentAction::Correct, DocumentAction::Reverse] as $action) {
        $availability = $policy->availability($this->payment, $this->w->financeB, $action);
        expect($availability->allowed)->toBeFalse()
            ->and(collect($availability->blockers)->pluck('code')->all())->toContain('bank_matched');
    }

    DB::table('bank_statement_lines')->where('id', $statement)->update(['matched_journal_line_id' => null, 'match_status' => 'unmatched']);

    expect($this->m->reverse($this->payment, $this->w->financeB)->outcome)->toBe(CorrectionOutcome::Executed);
});

it('refuses a payment that took a settlement discount until the discount is reversed', function () {
    $paid = $this->d->pay($this->billB, '380', '20', 'Supplier letter 16 Oct', 'Paid early');

    $availability = app(SupplierPaymentActionPolicy::class)->availability($paid, $this->w->financeB, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('payment_discount');
});

it('refuses paying more than a bill owes, and "nothing changed"', function () {
    expect(fn () => $this->m->preview($this->m->input($this->payment, DocumentAction::Correct, $this->m->paymentForm($this->payment, [
        'allocations' => [['supplier_bill_id' => $this->billA->id, 'amount' => '1200']],
    ])), $this->w->financeB))->toThrow(ValidationException::class, 'has only');

    expect(fn () => $this->m->preview($this->m->input($this->payment, DocumentAction::Correct, $this->m->paymentForm($this->payment)), $this->w->financeB))
        ->toThrow(ValidationException::class, 'Nothing was changed');
});

it('above Tk 20,000 a second person approves; the person who asked cannot', function () {
    $big = $this->d->bill($this->d->order([[$this->d->sugar, '300', '100.00']]), null, 'INV-BIG');
    $payment = $this->d->pay($big, '30000', by: $this->w->financeA);

    $result = $this->m->correct($payment, $this->w->financeB, $this->m->paymentForm($payment, ['allocations' => [['supplier_bill_id' => $big->id, 'amount' => '25000']]]));

    expect($result->outcome)->toBe(CorrectionOutcome::Requested);

    $this->actingAs($this->w->financeB)->post(route('corrections.requests.approve', $result->request))->assertForbidden();
    $this->actingAs($this->w->owner)->post(route('corrections.requests.approve', $result->request))->assertSessionHas('success');

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and((float) SupplierPayment::query()->whereNull('reversed_at')->where('supplier_id', $this->d->supplier->id)->orderByDesc('id')->first()->amount)->toBe(25000.0)
        ->and($this->m->problems())->toBe([]);
});

it('closed month: Finance is sent to the Owner, nothing posts until then', function () {
    $this->w->closeThrough('2026-10-16');

    $result = $this->m->reverse($this->payment, $this->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($this->payment->fresh()->isReversed())->toBeFalse()
        ->and($this->m->problems())->toBe([]);
});

it('the Correct page opens pre-filled and the payment page carries the buttons', function () {
    $this->actingAs($this->w->financeB)->get(route('purchasing.payments.correct', $this->payment))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/payments/correct')
            ->where('type', SupplierPaymentActionPolicy::TYPE)
            ->where('availability.allowed', true)
            ->where('bills.0.amount', '1000.00'));

    $this->actingAs($this->w->financeB)->get(route('purchasing.payments.show', $this->payment))->assertOk()
        ->assertInertia(fn ($page) => $page->where('corrections.type', SupplierPaymentActionPolicy::TYPE)->has('corrections.actions', 2));
});
