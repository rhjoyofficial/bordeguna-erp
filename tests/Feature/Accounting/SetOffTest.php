<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Actions\SetOffs;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Support\ApprovalCards;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PartyWorld;

/**
 * ⭐ Set-offs (decision D-F, 18 Oct 2026): one firm is our supplier and our
 * customer. A set-off is raised, waits for approval with nothing in the books,
 * posts Dr 2110 AP (supplier) / Cr 1140 AR (customer) on approval, reduces the
 * named bill and invoice exactly like a payment and a receipt, keeps both
 * subledger reconciliations tying, and is undone only through the corrections
 * process, which reopens both documents.
 */
beforeEach(function () {
    // New pages are not in the copied build manifest; the page component is what is asserted.
    $this->withoutVite();
    $this->p = PartyWorld::make();
    $this->w = $this->p->w;
    $this->statements = fn () => [
        CustomerStatement::for($this->p->customer, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-31')->endOfDay()),
        SupplierStatement::for($this->p->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-31')->endOfDay()),
    ];
    $this->ledgerProblems = fn () => collect(SubledgerReconciliation::reconcileAllBusinesses()['problems'])
        ->filter(fn ($p) => str_contains(json_encode($p), 'Receivable') || str_contains(json_encode($p), 'Payable'))
        ->values()->all();
});

afterEach(fn () => Carbon::setTestNow());

it('raises a numbered set-off that waits for approval and touches nothing', function () {
    $bill = $this->p->bill('8000');
    $invoice = $this->p->invoice('5000');
    $journals = Journal::count();

    $setOff = $this->p->raise([$bill->id => '3000'], [$invoice->id => '3000']);

    expect($setOff->number)->toBe('SET-2026-27-0001')
        ->and($setOff->status)->toBe(SetOff::PENDING)
        ->and($setOff->money()->format())->toBe('3,000.00')
        ->and($setOff->lines)->toHaveCount(2)
        ->and($setOff->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($setOff->approvalRequest->workflow_id)->not->toBeNull()
        ->and(Journal::count())->toBe($journals)
        ->and(PartyAdjustment::count())->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('8,000.00')
        ->and($invoice->fresh()->outstanding()->format())->toBe('5,000.00');

    expect($this->p->raise([$bill->id => '100'], [$invoice->id => '100'])->number)->toBe('SET-2026-27-0002');
});

it('posts a partial set-off on approval: Dr AP / Cr AR, the bill and the invoice reduced, both reconciliations tying', function () {
    $bill = $this->p->bill('8000');
    $invoice = $this->p->invoice('5000');

    $setOff = $this->p->posted([$bill->id => '3000'], [$invoice->id => '3000']);

    expect($setOff->status)->toBe(SetOff::POSTED)
        ->and($setOff->posted_by)->toBe($this->w->owner->id)
        ->and($setOff->journal_id)->not->toBeNull()
        ->and($setOff->journal->source_type)->toBe($setOff->getMorphClass())
        ->and($bill->fresh()->outstanding()->format())->toBe('5,000.00')
        ->and($invoice->fresh()->outstanding()->format())->toBe('2,000.00')
        // AP: Cr 8000 then Dr 3000; AR: Dr 5000 then Cr 3000.
        ->and($this->p->balance('accounts_payable'))->toBe(-500_000)
        ->and($this->p->balance('accounts_receivable'))->toBe(200_000);

    $lines = $setOff->journal->lines()->get();
    expect($lines->firstWhere('amount_minor', 300_000)->party_id)->toBe($this->p->supplier->id)
        ->and($lines->firstWhere('amount_minor', -300_000)->party_id)->toBe($this->p->customer->id);

    [$customerSide, $supplierSide] = ($this->statements)();
    expect($customerSide->isConsistent())->toBeTrue()
        ->and($supplierSide->isConsistent())->toBeTrue()
        ->and($customerSide->closing()->format())->toBe('2,000.00')
        ->and($supplierSide->closing()->format())->toBe('5,000.00')
        ->and(($this->ledgerProblems)())->toBe([]);
});

it('posts a full set-off across two bills and settles the invoice', function () {
    $billA = $this->p->bill('2000');
    $billB = $this->p->bill('3000');
    $invoice = $this->p->invoice('5000');

    $this->p->posted([$billA->id => '2000', $billB->id => '3000'], [$invoice->id => '5000']);

    expect($billA->fresh()->isSettled())->toBeTrue()
        ->and($billB->fresh()->isSettled())->toBeTrue()
        ->and($invoice->fresh()->isSettled())->toBeTrue()
        ->and($this->p->balance('accounts_payable'))->toBe(0)
        ->and($this->p->balance('accounts_receivable'))->toBe(0)
        ->and(($this->ledgerProblems)())->toBe([]);
});

it('refuses more than a document owes, unequal sides, a party mismatch, a future date and no reason', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('1000');

    expect(fn () => $this->p->raise([$bill->id => '1000.01'], [$invoice->id => '1000.01']))
        ->toThrow(ValidationException::class, 'has only 1,000.00 open to set off')
        ->and(fn () => $this->p->raise([$bill->id => '500'], [$invoice->id => '400']))
        ->toThrow(ValidationException::class, 'same amount on both sides')
        ->and(fn () => $this->p->raise([$bill->id => '500'], []))
        ->toThrow(ValidationException::class, 'at least one open bill and one open invoice')
        ->and(fn () => $this->p->raise([$bill->id => '100'], [$invoice->id => '100'], date: '2026-10-30'))
        ->toThrow(ValidationException::class, 'future');

    // Another customer's invoice, another supplier's bill.
    $stranger = Customer::create(['business_id' => $this->w->business->id, 'code' => 'OTHER', 'name' => 'Other Shop', 'type' => 'shop']);
    $strangerSupplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'OTHERS', 'name' => 'Other Mill']);
    $foreignInvoice = $this->p->invoice('500', customer: $stranger);
    $foreignBill = $this->p->bill('500', supplier: $strangerSupplier);

    expect(fn () => $this->p->raise([$bill->id => '100'], [$foreignInvoice->id => '100']))
        ->toThrow(ValidationException::class, 'does not belong to this customer')
        ->and(fn () => $this->p->raise([$foreignBill->id => '100'], [$invoice->id => '100']))
        ->toThrow(ValidationException::class, 'does not belong to this supplier');

    // Two parties that are not linked are never one party.
    expect(fn () => app(SetOffs::class)->raise($strangerSupplier, $stranger, '2026-10-15', [$foreignInvoice->id => '100'], [$foreignBill->id => '100'], 'x', $this->w->financeA))
        ->toThrow(ValidationException::class, 'is not linked to the supplier');

    expect(fn () => app(SetOffs::class)->raise($this->p->supplier, $this->p->customer->fresh(), '2026-10-15', [$invoice->id => '100'], [$bill->id => '100'], '   ', $this->w->financeA))
        ->toThrow(ValidationException::class, 'Say why');

    expect(SetOff::count())->toBe(0);
});

it('counts what a waiting set-off already claims, and refuses at approval when the bill was paid meanwhile', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('3000');

    $first = $this->p->raise([$bill->id => '800'], [$invoice->id => '800']);

    expect(fn () => $this->p->raise([$bill->id => '300'], [$invoice->id => '300']))
        ->toThrow(ValidationException::class, 'has only 200.00 open to set off');

    // The bill is settled another way while the set-off waits.
    app(RecordPartyAdjustment::class)->handle('supplier_discount', '2026-10-16', null, $this->p->supplier, [], [$bill->id => '500'], 'Discount', $this->w->owner);

    expect(fn () => $this->p->approve($first))->toThrow(ValidationException::class, 'only 500.00 outstanding');

    expect($first->fresh()->status)->toBe(SetOff::PENDING)
        ->and($first->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($invoice->fresh()->outstanding()->format())->toBe('3,000.00');
});

it('reverses only through the corrections process, reopening the bill and the invoice and keeping the books tying', function () {
    $bill = $this->p->bill('8000');
    $invoice = $this->p->invoice('5000');
    $setOff = $this->p->posted([$bill->id => '3000'], [$invoice->id => '3000']);

    // ⚠ The adjustment it posted cannot be removed on the Adjustments screen.
    expect(fn () => app(RecordPartyAdjustment::class)->remove($setOff->adjustment, $this->w->owner))
        ->toThrow(ValidationException::class, 'belongs to set-off '.$setOff->number);

    $result = $this->p->reverse($setOff, $this->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($result->reversalJournal->reverses_journal_id)->toBe($setOff->journal_id);

    $setOff->refresh();
    expect($setOff->status)->toBe(SetOff::REVERSED)
        ->and($setOff->isReversed())->toBeTrue()
        ->and($setOff->reversal_reason)->toContain('Agreed with the party by mistake')
        ->and(PartyAdjustment::count())->toBe(0)
        ->and($bill->fresh()->outstanding()->format())->toBe('8,000.00')
        ->and($invoice->fresh()->outstanding()->format())->toBe('5,000.00')
        ->and($this->p->balance('accounts_payable'))->toBe(-800_000)
        ->and($this->p->balance('accounts_receivable'))->toBe(500_000);

    [$customerSide, $supplierSide] = ($this->statements)();
    expect($customerSide->isConsistent())->toBeTrue()
        ->and($supplierSide->isConsistent())->toBeTrue()
        ->and(($this->ledgerProblems)())->toBe([]);

    // Once only.
    expect(fn () => $this->p->reverse($setOff, $this->w->financeC))->toThrow(CorrectionBlockedException::class);
    expect(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1);
});

it('offers Reverse only on a posted set-off, never Correct, and follows the policy kit rules', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('1000');
    $pending = $this->p->raise([$bill->id => '100'], [$invoice->id => '100']);
    $posted = $this->p->posted([$bill->id => '200'], [$invoice->id => '200']);
    $policy = app(DocumentPolicyRegistry::class)->for(SetOffActionPolicy::TYPE);

    expect($policy->actionsFor($pending, $this->w->financeB)->actions)->toBe([])
        ->and(collect($policy->actionsFor($posted, $this->w->financeB)->actions)->map(fn ($a) => $a->action)->all())->toBe([DocumentAction::Reverse])
        ->and($policy->availability($posted, $this->w->financeB, DocumentAction::Reverse)->allowed)->toBeTrue()
        ->and($policy->availability($posted, $this->w->financeB, DocumentAction::Correct)->allowed)->toBeFalse()
        ->and($policy->actionsFor($posted, $this->w->manager)->actions)->toBe([])
        ->and($policy->effectProfile()->uncoveredEffects())->toBe([]);
});

it('reaches the approval inbox with its facts, needs a reason to approve one\'s own, and a rejection posts nothing', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('1000');
    $setOff = $this->p->raise([$bill->id => '400'], [$invoice->id => '400'], by: $this->w->owner);

    $this->actingAs($this->w->owner);
    $card = app(ApprovalCards::class)->present($setOff->approvalRequest);

    expect($card['type'])->toBe('Set-off')
        ->and($card['approve_url'])->toBe(route('accounting.set-offs.approve', $setOff))
        ->and($card['view_url'])->toBe(route('accounting.set-offs.show', $setOff))
        ->and($card['self_approval'])->toBeTrue()
        ->and(collect($card['details'])->pluck('label')->all())->toContain('Party', 'Amount', 'Books')
        ->and($card['lines'])->toHaveCount(2);

    // The Owner approving his own set-off: allowed, with a reason.
    expect(fn () => $this->p->approve($setOff))->toThrow(ValidationException::class);
    expect($this->p->approve($setOff, note: 'Rahman confirmed by phone')->status)->toBe(SetOff::POSTED);

    $other = $this->p->raise([$bill->id => '100'], [$invoice->id => '100']);
    $this->actingAs($this->w->owner)->post(route('accounting.set-offs.reject', $other), ['review_note' => 'Not agreed yet'])->assertRedirect();

    expect($other->fresh()->status)->toBe(SetOff::REJECTED)
        ->and($other->fresh()->journal_id)->toBeNull()
        ->and($bill->fresh()->outstanding()->format())->toBe('600.00');
});

it('lets Finance and the Owner raise one over HTTP, the Owner approve it, and nobody else', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('1000');
    $payload = ['supplier_id' => $this->p->supplier->id, 'set_off_on' => '2026-10-15', 'bills' => [$bill->id => '250'], 'invoices' => [$invoice->id => '250'], 'reason' => 'Netted'];

    $this->actingAs($this->w->manager)->post(route('accounting.set-offs.store'), $payload)->assertForbidden();
    $this->actingAs($this->w->financeA)->post(route('accounting.set-offs.store'), $payload)->assertRedirect(route('accounting.set-offs.index'));

    $setOff = SetOff::sole();

    // Finance holds no voucher.post: the stage does not admit them.
    $this->actingAs($this->w->financeB)->post(route('accounting.set-offs.approve', $setOff))->assertForbidden();
    $this->actingAs($this->w->owner)->post(route('accounting.set-offs.approve', $setOff))->assertRedirect()->assertSessionHas('success');

    expect($setOff->fresh()->status)->toBe(SetOff::POSTED);

    $this->actingAs($this->w->financeA)->get(route('accounting.set-offs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/set-offs/index')
            ->where('setOffs.0.number', 'SET-2026-27-0001')
            ->where('setOffs.0.status', 'posted')
            ->where('setOffs.0.actions.0.action', 'reverse')
            ->where('parties.0.supplier_id', $this->p->supplier->id)
            ->where('canRaise', true));

    $this->actingAs($this->w->owner)->get(route('accounting.set-offs.show', $setOff))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/set-offs/show')
            ->where('setOff.number', 'SET-2026-27-0001')
            ->where('setOff.bills.0.amount', '250.00'));

    // Reading needs BOTH sides: purchasing.reports.view alone is not enough.
    $buyer = User::factory()->create(['name' => 'Buyer']);
    $buyer->businesses()->attach($this->w->business->id);
    $buyer->givePermissionTo('purchasing.reports.view');
    $this->actingAs($buyer)->get(route('accounting.set-offs.index'))->assertForbidden();
});

it('never posts a set-off from the Adjustments screen', function () {
    $bill = $this->p->bill('1000');
    $invoice = $this->p->invoice('1000');

    $this->actingAs($this->w->owner)->post(route('accounting.adjustments.store'), [
        'kind' => 'set_off', 'adjusted_on' => '2026-10-15', 'customer_id' => $this->p->customer->id, 'supplier_id' => $this->p->supplier->id,
        'invoices' => [$invoice->id => '100'], 'bills' => [$bill->id => '100'], 'reason' => 'Direct',
    ])->assertSessionHasErrors('kind');

    expect(PartyAdjustment::count())->toBe(0);
});
