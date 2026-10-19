<?php

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Purchasing\Actions\FixSupplierBillDetails;
use App\Modules\Purchasing\Corrections\SupplierBillActionPolicy;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): supplier bills in the corrections
 * process (FINAL-PLAN 3.3, 4.2). Correct and Reverse while unpaid, "Correct
 * paid bill" (the payment moves, never reversed), Fix details, and the books
 * tying after every one: AP per supplier, the supplier ledger view, GRNI.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    // 50 kg of salt at Tk 20, received 10 Oct, billed 12 Oct: Tk 1,000.
    $this->order = $this->d->order([[$this->d->salt, '50', '20.00']]);
    $this->bill = $this->d->bill($this->order);
});

afterEach(fn () => Carbon::setTestNow());

it('corrects an unpaid bill: reversed on its own date, the right bill recorded under the same number, the books tie', function () {
    $originalJournal = $this->m->journalOf($this->bill);

    $result = $this->m->correct($this->bill, $this->w->financeB, $this->m->billForm($this->bill, ['bill_date' => '2026-10-11'], [['unit_cost' => '21']]));

    $successor = $result->successor;

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($successor->bill_number)->toBe('INV-100')
        ->and($successor->bill_date->toDateString())->toBe('2026-10-11')
        ->and((float) $successor->total)->toBe(1050.0)
        ->and($this->bill->fresh()->isReversed())->toBeTrue()
        ->and($this->bill->fresh()->reversal_note)->toBe('Typed it wrong on the screen')
        ->and(CorrectionChain::correctionOfLabel($successor))->toBe('Correction of Bill INV-100')
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-12')
        ->and($result->reversalJournal->reverses_journal_id)->toBe($originalJournal)
        ->and((float) $this->order->lines()->first()->quantity_invoiced)->toBe(50.0)
        ->and($this->m->payableOwed())->toBe(105000)
        ->and($this->m->problems())->toBe([]);
});

it('reverses an unpaid bill on its own date by default, or today when asked; the goods are billable again', function () {
    $result = $this->m->reverse($this->bill, $this->w->financeB);

    expect($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-12')
        ->and($this->bill->fresh()->isReversed())->toBeTrue()
        ->and((float) $this->order->lines()->first()->quantity_invoiced)->toBe(0.0)
        ->and($this->m->payableOwed())->toBe(0)
        ->and($this->m->problems())->toBe([]);

    $again = $this->d->bill($this->order->fresh('lines'), null, 'INV-100', '2026-10-13');
    $today = $this->m->reverse($again, $this->w->financeB, ReverseDateChoice::Today);

    expect($today->reversalJournal->journal_date->toDateString())->toBe('2026-10-17')
        ->and($this->m->problems())->toBe([]);
});

it('corrects a PAID bill: the payment moves to the corrected bill, is not reversed, and no bank line changes', function () {
    $payment = $this->d->pay($this->bill, '600');
    $paymentJournal = $this->m->journalOf($payment);
    $journalsBefore = $this->m->journalCount();

    // Reverse is refused while paid; Correct is offered.
    $actions = app(SupplierBillActionPolicy::class)->actionsFor($this->bill->fresh(), $this->w->financeB);
    expect($actions->get(DocumentAction::Reverse)->allowed)->toBeFalse()
        ->and(collect($actions->get(DocumentAction::Reverse)->blockers)->pluck('code')->all())->toContain('bill_settled')
        ->and($actions->get(DocumentAction::Correct)->allowed)->toBeTrue();

    $result = $this->m->correct($this->bill->fresh(), $this->w->financeB, $this->m->billForm($this->bill, [], [['quantity' => '45']]));
    $successor = $result->successor->fresh();

    expect((float) $successor->total)->toBe(900.0)
        ->and($successor->paidTotal()->minor)->toBe(60000)
        ->and($successor->outstanding()->minor)->toBe(30000)
        ->and($this->bill->fresh()->paidTotal()->minor)->toBe(0)
        ->and($payment->fresh()->isReversed())->toBeFalse()
        ->and($this->m->journalOf($payment))->toBe($paymentJournal)
        // Only the bill's reversal and the corrected bill's journal.
        ->and($this->m->journalCount())->toBe($journalsBefore + 2)
        ->and((float) $this->order->lines()->first()->quantity_invoiced)->toBe(45.0)
        ->and($this->m->payableOwed())->toBe(30000)
        ->and($this->m->problems())->toBe([]);

    expect(Activity::query()->where('subject_id', $successor->id)->where('description', 'like', 'Payments and credits moved here%')->exists())->toBeTrue();
});

it('refuses a corrected bill smaller than what was already paid, and changes nothing', function () {
    $this->d->pay($this->bill, '800');
    $before = $this->m->journalCount();

    expect(fn () => $this->m->preview($this->m->input($this->bill->fresh(), DocumentAction::Correct, $this->m->billForm($this->bill, [], [['quantity' => '30']])), $this->w->financeB))
        ->toThrow(ValidationException::class, 'less than the Tk 800 already paid');

    expect($this->m->journalCount())->toBe($before)
        ->and($this->bill->fresh()->isReversed())->toBeFalse()
        ->and($this->m->problems())->toBe([]);
});

it('a paid bill keeps its supplier', function () {
    $this->d->pay($this->bill, '100');
    $other = Supplier::create(['business_id' => $this->w->business->id, 'name' => 'Another', 'code' => 'SUP-009']);

    expect(fn () => $this->m->preview($this->m->input($this->bill->fresh(), DocumentAction::Correct, $this->m->billForm($this->bill, ['supplier_id' => $other->id])), $this->w->financeB))
        ->toThrow(ValidationException::class, 'keeps its supplier');
});

it('blocks both actions while a supplier discount stands on the bill, with the sentence', function () {
    $bill = $this->d->bill($this->d->order([[$this->d->sugar, '10', '10.00']]), ['mode' => 'amount', 'value' => '10', 'reference' => 'INV-D'], 'INV-D');
    $policy = app(SupplierBillActionPolicy::class);

    foreach ([DocumentAction::Correct, DocumentAction::Reverse] as $action) {
        $availability = $policy->availability($bill, $this->w->financeB, $action);
        expect($availability->allowed)->toBeFalse()
            ->and(collect($availability->blockers)->pluck('message')->implode(' '))->toContain('Reverse the discount first');
    }

    expect(SupplierDiscount::count())->toBe(1);
});

it('refuses somebody without accounting.correct, and shows them no button', function () {
    expect(app(SupplierBillActionPolicy::class)->actionsFor($this->bill, $this->w->manager)->actions)->toHaveCount(1) // Fix details only
        ->and(fn () => $this->m->preview($this->m->input($this->bill, DocumentAction::Reverse), $this->w->manager))->toThrow(AuthorizationException::class);
});

it('refuses "nothing changed" and a bill number another live bill holds', function () {
    expect(fn () => $this->m->preview($this->m->input($this->bill, DocumentAction::Correct, $this->m->billForm($this->bill)), $this->w->financeB))
        ->toThrow(ValidationException::class, 'Nothing was changed');

    $this->d->bill($this->d->order([[$this->d->sugar, '10', '10.00']]), null, 'INV-200');

    expect(fn () => $this->m->preview($this->m->input($this->bill, DocumentAction::Correct, $this->m->billForm($this->bill, ['bill_number' => 'INV-200'])), $this->w->financeB))
        ->toThrow(ValidationException::class, 'already been recorded');
});

it('closed month: Finance may only ask the Owner; the Owner corrects on the original date as a one-document exception', function () {
    $this->w->closeThrough('2026-10-12');

    $finance = app(SupplierBillActionPolicy::class)->availability($this->bill, $this->w->financeB, DocumentAction::Correct);
    expect($finance->isRequestable())->toBeTrue();

    $asked = $this->m->correct($this->bill, $this->w->financeB, $this->m->billForm($this->bill, [], [['unit_cost' => '19']]));
    expect($asked->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($this->bill->fresh()->isReversed())->toBeFalse();

    $asked->request->forceFill(['status' => 'withdrawn', 'pending_key' => null])->save();

    $result = $this->m->correct($this->bill, $this->w->owner, $this->m->billForm($this->bill, [], [['unit_cost' => '19']]));

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->link->closed_period_exception)->toBeTrue()
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-12')
        ->and($result->reversalJournal->period_exception_id)->not->toBeNull()
        ->and(Journal::find($this->m->journalOf($result->successor))->period_exception_id)->not->toBeNull()
        ->and($this->m->problems())->toBe([]);
});

it('above Tk 20,000 a second person approves, then it posts', function () {
    $bill = $this->d->bill($this->d->order([[$this->d->sugar, '300', '100.00']]), null, 'INV-BIG');
    $result = $this->m->correct($bill, $this->w->financeB, $this->m->billForm($bill, [], [['unit_cost' => '95']]));

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($bill->fresh()->isReversed())->toBeFalse();

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $result->request))->assertSessionHas('success');

    expect($bill->fresh()->isReversed())->toBeTrue()
        ->and(CorrectionLink::sole()->approved_by)->toBe($this->w->financeC->id)
        ->and((float) SupplierBill::query()->whereNull('reversed_at')->where('bill_number', 'INV-BIG')->sole()->total)->toBe(28500.0)
        ->and($this->m->problems())->toBe([]);
});

it('a double submit with the same key makes one correction', function () {
    $input = $this->m->input($this->bill, DocumentAction::Correct, $this->m->billForm($this->bill, [], [['unit_cost' => '21']]), key: 'same-key-0000000001');
    $token = $this->m->preview($input, $this->w->financeB)->previewToken;
    $service = app(CorrectionService::class);

    $first = $service->submit($input, $this->w->financeB, $token);
    $second = $service->submit($input, $this->w->financeB, $token);

    expect($first->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($second->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and(SupplierBill::count())->toBe(2)
        ->and(CorrectionLink::count())->toBe(1);

    // A second Correct with a new key is refused: already corrected.
    expect(fn () => $this->m->correct($this->bill->fresh(), $this->w->financeB, $this->m->billForm($this->bill, [], [['unit_cost' => '22']])))
        ->toThrow(CorrectionBlockedException::class);
});

it('Fix details: the bill number, due date and note change with a reason; no journal moves; history and report keep it', function () {
    $journals = $this->m->journalCount();
    $seen = FixSupplierBillDetails::fingerprint($this->bill);

    $this->actingAs($this->w->financeB)
        ->post(route('purchasing.bills.fix-details', $this->bill), ['bill_number' => 'INV-1OO', 'due_date' => '2026-11-10', 'note' => 'Supplier typed O for 0', 'reason_text' => 'Hi', 'seen' => $seen])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->w->financeB)
        ->post(route('purchasing.bills.fix-details', $this->bill), ['bill_number' => 'INV-1OO', 'due_date' => '2026-11-10', 'note' => 'Supplier typed O for 0', 'reason_text' => 'Typo in the bill number', 'seen' => $seen])
        ->assertSessionHasNoErrors();

    $bill = $this->bill->fresh();
    $log = Activity::query()->where('log_name', 'corrections')->where('event', 'fix_details')->sole();

    expect($bill->bill_number)->toBe('INV-1OO')
        ->and($bill->due_date->toDateString())->toBe('2026-11-10')
        ->and($bill->due_date_source)->toBe('changed')
        ->and($bill->note)->toBe('Supplier typed O for 0')
        ->and($this->m->journalCount())->toBe($journals)
        ->and($log->properties['before']['bill_number'])->toBe('INV-100')
        ->and($log->properties['after']['bill_number'])->toBe('INV-1OO')
        ->and($this->m->problems())->toBe([]);

    // The page that showed the old values is stale now.
    $this->actingAs($this->w->financeB)
        ->post(route('purchasing.bills.fix-details', $this->bill), ['bill_number' => 'INV-X', 'reason_text' => 'Another typo here', 'seen' => $seen])
        ->assertSessionHasErrors('stale');

    // A number another live bill holds is refused.
    $this->d->bill($this->d->order([[$this->d->sugar, '10', '10.00']]), null, 'INV-200');
    $this->actingAs($this->w->financeB)
        ->post(route('purchasing.bills.fix-details', $bill), ['bill_number' => 'INV-200', 'reason_text' => 'Typo in the bill number', 'seen' => FixSupplierBillDetails::fingerprint($bill)])
        ->assertSessionHasErrors('bill_number');

    // Sales may not.
    $this->actingAs($this->w->sales)
        ->post(route('purchasing.bills.fix-details', $bill), ['bill_number' => 'INV-Z', 'reason_text' => 'Typo in the bill number', 'seen' => FixSupplierBillDetails::fingerprint($bill)])
        ->assertForbidden();
});

it('purchasing approvals: a corrected bill the gate would hold cannot go through on one click below the corrections limit', function () {
    config(['accounting.purchasing_approvals' => true]);
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['purchasing_approve_price_mismatch' => true, 'purchasing_price_tolerance_percent' => 5]);

    expect(fn () => $this->m->preview($this->m->input($this->bill, DocumentAction::Correct, $this->m->billForm($this->bill, [], [['unit_cost' => '30']])), $this->w->financeB))
        ->toThrow(ValidationException::class, 'needs a purchasing approval');

    expect(CorrectionRequest::count())->toBe(0)
        ->and(SupplierPayment::count())->toBe(0);
});

it('the Correct page and the bill page carry the actions and the chain', function () {
    $this->actingAs($this->w->financeB)->get(route('purchasing.bills.correct', $this->bill))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/bills/correct')->where('type', SupplierBillActionPolicy::TYPE)->where('availability.allowed', true));

    $result = $this->m->correct($this->bill, $this->w->financeB, $this->m->billForm($this->bill, [], [['unit_cost' => '21']]));

    $this->actingAs($this->w->financeB)->get(route('purchasing.bills.show', $result->successor))->assertOk()
        ->assertInertia(fn ($page) => $page->where('corrections.correction_of.number', 'Bill INV-100'));
    $this->actingAs($this->w->financeB)->get(route('purchasing.bills.show', $this->bill))->assertOk()
        ->assertInertia(fn ($page) => $page->where('corrections.corrected_into.number', 'Bill INV-100'));

    expect(DB::table('correction_links')->count())->toBe(1);
});
