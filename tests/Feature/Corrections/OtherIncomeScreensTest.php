<?php

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CorrectionWorld;

/**
 * The Other income screens after a correction (PHASE-0-CONTRACT v2, 3.2 and
 * 3.7 test 33): the list hides corrected originals by default, "Show
 * corrections" brings them back with their badge and link, the Correct page
 * is the form pre-filled, the print view carries the stamp.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('33. the list hides the corrected original by default; the toggle shows it with the Corrected badge and the link', function () {
    $original = $this->w->record('5000', '2026-10-15');
    $successor = $this->w->correct($original, $this->w->financeB, ['amount' => '4500'])->successor;

    $this->actingAs($this->w->financeC)->get('/accounting/other-income')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/other-income/index')
            ->where('showCorrections', false)
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.voucher_number', $successor->voucher_number)
            ->where('vouchers.data.0.correction_of', $original->voucher_number)
            ->where('vouchers.data.0.actions.0.action', 'correct')
            ->where('vouchers.data.0.actions.0.allowed', true));

    $this->actingAs($this->w->financeC)->get('/accounting/other-income?show_corrections=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('vouchers.data', 2)
            ->where('vouchers.data.1.voucher_number', $original->voucher_number)
            ->where('vouchers.data.1.status', 'corrected')
            ->where('vouchers.data.1.corrected_to', $successor->voucher_number)
            ->where('vouchers.data.1.actions.0.allowed', false)
            ->where('vouchers.data.1.actions.0.blockers.0.code', 'already_corrected'));
});

it('shows no correction buttons to somebody who may read but not correct', function () {
    $this->w->record('5000', '2026-10-15');
    $reader = $this->w->member('Finance', 'Reader');
    $reader->syncRoles([]);
    $reader->givePermissionTo('accounting.view');

    $this->actingAs($reader)->get('/accounting/other-income')
        ->assertInertia(fn (Assert $page) => $page->where('vouchers.data.0.actions', []));
});

it('the Correct page is the form pre-filled; a reversed voucher sends you back to the list', function () {
    $voucher = $this->w->record('5000', '2026-10-15');

    $this->actingAs($this->w->financeB)->get("/accounting/other-income/{$voucher->id}/correct")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/other-income/correct')
            ->where('voucher.voucher_number', $voucher->voucher_number)
            ->where('voucher.lines.0.amount', '5000.00')
            ->where('availability.allowed', true)
            ->where('type', 'accounting.income_voucher')
            ->has('correctionReasons', 8));

    $this->actingAs($this->w->manager)->get("/accounting/other-income/{$voucher->id}/correct")->assertForbidden();

    $this->w->reverse($voucher, $this->w->financeB);
    $this->actingAs($this->w->financeB)->get("/accounting/other-income/{$voucher->id}/correct")->assertRedirect('/accounting/other-income');
});

it('prints the original with its stamp and the successor with "Correction of"', function () {
    $original = $this->w->record('5000', '2026-10-15');
    $successor = $this->w->correct($original, $this->w->financeB, ['amount' => '4500'])->successor;

    $this->actingAs($this->w->financeC)->get("/accounting/other-income/{$original->id}/print")
        ->assertInertia(fn (Assert $page) => $page->where('voucher.stamp', "CORRECTED, see {$successor->voucher_number}"));

    $this->actingAs($this->w->financeC)->get("/accounting/other-income/{$successor->id}/print")
        ->assertInertia(fn (Assert $page) => $page->where('voucher.stamp', null)->where('voucher.correction_of', $original->voucher_number));
});

it('recording a new voucher that looks like a recent one asks first, and Save anyway keeps the answer', function () {
    $this->w->record('4500', '2026-10-13', head: $this->w->interest);
    $form = [
        'money_account_id' => $this->w->bank->id, 'received_on' => '2026-10-14', 'received_from' => 'BRAC',
        'lines' => [['account_id' => $this->w->interest->id, 'amount' => '4500']],
    ];

    $this->actingAs($this->w->financeB)->post('/accounting/other-income', $form)
        ->assertSessionHasErrors('_duplicate')
        ->assertSessionHas('duplicateWarning');

    $token = session('duplicateWarning')['ack_token'];

    $this->actingAs($this->w->financeB)->post('/accounting/other-income', $form + ['duplicate_ack' => $token])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Income recorded.');

    expect(App\Modules\Foundation\Models\DuplicateAcknowledgement::count())->toBe(1);
});

it('the Recycle bin and the Corrections report pages open for the Owner', function () {
    $this->actingAs($this->w->owner)->get('/settings/recycle-bin')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/recycle-bin')->where('entries', []));

    $this->actingAs($this->w->owner)->get('/accounting/corrections')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/corrections/index')->where('month', '2026-10'));

    $this->actingAs($this->w->financeB)->get('/settings/recycle-bin')->assertForbidden();
});
