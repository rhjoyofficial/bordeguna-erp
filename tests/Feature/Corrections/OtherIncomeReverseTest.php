<?php

use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CorrectionWorld;

/**
 * Other income, Reverse (PHASE-0-CONTRACT v2, 3.7 tests 1 to 7).
 *
 * The voucher is KEPT and marked; a mirror journal dated on the voucher's own
 * date undoes it, so the month nets to zero; the reason is required by the
 * server; a second reversal is refused by the status AND by the database.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('1. reverses in an open month on the original date, marks the voucher, and nets the month to zero', function () {
    $voucher = $this->w->record('5000', '2026-10-15');

    $result = $this->w->reverse($voucher, $this->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($result->reversalJournal->reverses_journal_id)->toBe($voucher->fresh()->journal_id)
        ->and($result->message)->toBe("{$voucher->voucher_number} reversed.");

    $voucher->refresh();
    expect($voucher->isReversed())->toBeTrue()
        ->and($voucher->reversed_on->toDateString())->toBe('2026-10-15')
        ->and($voucher->reversed_by)->toBe($this->w->financeB->id)
        ->and($voucher->reversal_reason)->toContain('Wrong amount typed in')
        ->and($voucher->reversal_journal_id)->toBe($result->reversalJournal->id)
        ->and($voucher->correctionStatus())->toBe('reversed');

    expect($this->w->balance('11201', '2026-10-01', '2026-10-31'))->toBe(0)
        ->and($this->w->balance('4910', '2026-10-01', '2026-10-31'))->toBe(0)
        ->and(CorrectionLink::count())->toBe(1);
});

it('2. refuses a reverse with no reason, a short reason or only spaces, from the server', function () {
    $voucher = $this->w->record();
    $url = '/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/reverse";

    foreach ([[], ['reason_text' => 'ok'], ['reason_text' => '      ']] as $body) {
        $this->actingAs($this->w->financeB)
            ->postJson($url, $body + ['idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_text');
    }

    expect($voucher->fresh()->isReversed())->toBeFalse()
        ->and(Journal::count())->toBe(1);
});

it('3. reverses "Today" in an open month, and refuses Today on Correct', function () {
    $voucher = $this->w->record('5000', '2026-10-15');

    $result = $this->w->reverse($voucher, $this->w->financeB, ReverseDateChoice::Today);
    expect($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-17');

    $other = $this->w->record('700', '2026-10-15');
    $preview = $this->w->preview($this->w->input($other, DocumentAction::Correct, $this->w->replacement($other, ['amount' => '600']), ReverseDateChoice::Today), $this->w->financeB);

    expect($preview->previewToken)->toBeNull()
        ->and(collect($preview->blockers)->pluck('code')->all())->toContain('today_not_allowed');
});

it('4. refuses a second reverse, and the database refuses it even when the status check is skipped', function () {
    $voucher = $this->w->record();
    $this->w->reverse($voucher, $this->w->financeB);

    expect(fn () => $this->w->reverse($voucher->fresh(), $this->w->financeC))
        ->toThrow(CorrectionBlockedException::class);

    // ⚠ The forged path: a correction link row for the same original cannot be
    // written twice. live_key is UNIQUE.
    $link = CorrectionLink::sole();
    expect(fn () => DB::table('correction_links')->insert([
        'business_id' => $link->business_id, 'action' => 'reverse', 'original_type' => $link->original_type, 'original_id' => $link->original_id,
        'original_number' => $link->original_number, 'original_date' => '2026-10-15', 'reason_text' => 'forged', 'actor_id' => $this->w->financeC->id,
        'date_used' => '2026-10-15', 'date_rule' => 'original', 'amount_basis_minor' => 1, 'original_minor' => 1,
        'idempotency_key' => (string) Str::uuid(), 'live_key' => $link->live_key, 'created_at' => now(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);

    expect(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1);
});

it('4b. a stale model that skips the status check still cannot post a second reversal', function () {
    $voucher = $this->w->record();
    $first = $this->w->input($voucher, DocumentAction::Reverse);
    $second = $this->w->input($voucher, DocumentAction::Reverse);

    $service = app(CorrectionService::class);
    $previewA = $service->preview($first, $this->w->financeB);
    $previewB = $service->preview($second, $this->w->financeC);

    $service->submit($first, $this->w->financeB, $previewA->previewToken);

    // B's preview was taken before A's reversal: the fingerprint no longer
    // matches, and if it did, live_key would refuse it.
    expect(fn () => $service->submit($second, $this->w->financeC, $previewB->previewToken))->toThrow(Exception::class);

    expect(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1)
        ->and(CorrectionLink::count())->toBe(1);
});

it('5. a bank-matched money line blocks Reverse and Correct with the exact sentence; unmatching allows it', function () {
    $voucher = $this->w->record();
    $line = $this->w->matchOnBankStatement($voucher);

    $policy = app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE);

    foreach ([DocumentAction::Reverse, DocumentAction::Correct] as $action) {
        $availability = $policy->availability($voucher, $this->w->financeB, $action);
        expect($availability->allowed)->toBeFalse()
            ->and(collect($availability->blockers)->pluck('message')->all())->toContain("{$action->label()}: matched on the bank statement. Unmatch it first.");
    }

    DB::table('bank_statement_lines')->where('id', $line)->update(['matched_journal_line_id' => null]);

    expect($policy->availability($voucher, $this->w->financeB, DocumentAction::Reverse)->allowed)->toBeTrue();
});

it('6. a user without accounting.correct gets 403 on preview and on submit, and sees no buttons', function () {
    $voucher = $this->w->record();
    $base = '/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}";
    $body = ['reason_text' => 'Wrong amount typed in', 'idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'];

    $this->actingAs($this->w->manager)->postJson("{$base}/reverse/preview", $body)->assertNotFound();
    $this->actingAs($this->w->sales)->postJson("{$base}/reverse/preview", $body)->assertNotFound();

    // The Manager cannot read the books at all, so the record is hidden (404).
    // A reader WITH accounting.view but without accounting.correct gets 403.
    $reader = $this->w->member('Finance', 'Reader');
    \Spatie\Permission\Models\Role::where('name', 'Finance')->where('business_id', $this->w->business->id)->first();
    $reader->syncRoles([]);
    $reader->givePermissionTo('accounting.view');

    $this->actingAs($reader)->postJson("{$base}/reverse/preview", $body)->assertForbidden();
    $this->actingAs($reader)->postJson("{$base}/reverse", $body)->assertForbidden();
    $this->actingAs($reader)->getJson("{$base}/actions")->assertOk()->assertJsonPath('actions', []);

    expect($voucher->fresh()->isReversed())->toBeFalse();
});

it('7. a voucher of another business is a 404', function () {
    $voucher = $this->w->record();
    $outsider = $this->w->member('Finance', 'Other finance', $this->w->other);

    $this->actingAs($outsider)
        ->postJson('/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/reverse/preview", ['reason_text' => 'Wrong amount typed in', 'idempotency_key' => (string) Str::uuid()])
        ->assertNotFound();

    $this->actingAs($outsider)->getJson('/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/actions")->assertNotFound();
    $this->actingAs($outsider)->getJson("/records/accounting.nothing/{$voucher->id}/actions")->assertNotFound();
});
