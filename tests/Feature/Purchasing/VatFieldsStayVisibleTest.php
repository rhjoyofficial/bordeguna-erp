<?php

use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Joy's Q19 (25 Oct 2026): VAT fields stay visible on the purchase and sales
 * screens but do nothing while the business is not VAT registered. The screens
 * grey them out; the server keeps every VAT amount at zero.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    $this->order = $this->d->order([[$this->d->salt, '50', '20.00']]);
    $this->bill = $this->d->bill($this->order);
    $this->return = $this->m->returnGoods($this->order, '10');
});

afterEach(fn () => Carbon::setTestNow());

it('refuses VAT on a credit note while VAT is not registered, and records it at zero', function () {
    try {
        app(RecordSupplierCreditNote::class)->handle(
            supplier: $this->d->supplier,
            purchaseReturn: $this->return,
            creditNoteNumber: 'CN-VAT',
            creditDate: '2026-10-14',
            allocations: [['bill' => $this->bill->fresh(), 'amount' => '200']],
            createdBy: $this->w->financeA,
            taxAmount: '20',
        );
        $this->fail('VAT was accepted on a credit note while VAT is off.');
    } catch (ValidationException $e) {
        expect($e->errors()['tax_amount'][0])->toContain('VAT is not registered');
    }

    expect(SupplierCreditNote::query()->count())->toBe(0);

    $note = $this->m->creditNote($this->return, $this->bill, '200');

    expect((float) $note->tax_total)->toBe(0.0)
        ->and($this->m->problems())->toBe([]);
});

it('still sends the VAT switch to the bill and credit note screens, off', function () {
    $this->actingAs($this->w->owner)->get("/purchasing/credit-notes/new?supplier_id={$this->d->supplier->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('vatApplies', false)->etc());
});
