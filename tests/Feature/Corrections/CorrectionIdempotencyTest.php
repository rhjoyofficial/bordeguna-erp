<?php

use App\Models\User;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\Exceptions\PreviewExpiredException;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use App\Modules\Foundation\Http\Middleware\PreventDuplicateSubmission;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CorrectionWorld;

/**
 * Concurrency and idempotency (PHASE-0-CONTRACT v2, 3.7 tests 12 to 16, and
 * ChatGPT's 45, 46, 48). ⚠ Also run on MySQL (see the release notes): on
 * SQLite lockForUpdate does nothing, so the re-read, the fingerprint and the
 * UNIQUE indexes are what these prove.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->service = app(CorrectionService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('12. the same idempotency key twice (after the first finished) returns AlreadyDone with the same link: one reversal, one successor', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $input = $this->w->input($voucher, DocumentAction::Correct, $this->w->replacement($voucher, ['amount' => '4500']));
    $preview = $this->w->preview($input, $this->w->financeB);

    $first = $this->service->submit($input, $this->w->financeB, $preview->previewToken);
    $again = $this->service->submit($input, $this->w->financeB, $preview->previewToken);

    expect($first->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($again->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and($again->link->id)->toBe($first->link->id)
        ->and($again->message)->toBe($first->message)
        ->and(CorrectionLink::count())->toBe(1)
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1)
        ->and(IncomeVoucher::count())->toBe(2);

    // Over HTTP too: the retry answers the same, and nothing new is posted.
    $body = ['reason_text' => 'Wrong amount typed in', 'idempotency_key' => $input->idempotencyKey, 'preview_token' => 'anything',
        'replacement' => $input->replacement];
    $this->actingAs($this->w->financeB)
        ->postJson('/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/correct", $body)
        ->assertOk()->assertJsonPath('outcome', 'already_done')->assertJsonPath('link_id', $first->link->id);

    expect(IncomeVoucher::count())->toBe(2);
});

it('12b. a key already used for one record cannot be replayed on another', function () {
    $a = $this->w->record('5000', '2026-10-15');
    $b = $this->w->record('7000', '2026-10-15');
    $input = $this->w->input($a, DocumentAction::Reverse);
    $this->w->submit($input, $this->w->financeB);

    $onB = $this->w->input($b, DocumentAction::Reverse, key: $input->idempotencyKey);

    expect(fn () => $this->service->submit($onB, $this->w->financeB, 'x'))->toThrow(PreviewExpiredException::class)
        ->and($b->fresh()->isReversed())->toBeFalse();
});

it('13. two different keys for the same voucher: exactly one succeeds, the other is refused', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $a = $this->w->input($voucher, DocumentAction::Correct, $this->w->replacement($voucher, ['amount' => '4500']));
    $b = $this->w->input($voucher, DocumentAction::Reverse);
    $previewA = $this->w->preview($a, $this->w->financeB);
    $previewB = $this->w->preview($b, $this->w->financeC);

    $this->service->submit($a, $this->w->financeB, $previewA->previewToken);

    expect(fn () => $this->service->submit($b, $this->w->financeC, $previewB->previewToken))
        ->toThrow(CorrectionBlockedException::class);

    expect(CorrectionLink::count())->toBe(1)
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1);
});

it('14. a stale preview: the record changed after the preview (its month was closed), the submit says so and nothing posts', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $input = $this->w->input($voucher, DocumentAction::Correct, $this->w->replacement($voucher, ['amount' => '4500']));
    $preview = $this->w->preview($input, $this->w->financeB);

    // Somebody changes the voucher's journal state behind the preview: the
    // period boundary moves (the fingerprint includes it).
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->w->owner);

    expect(fn () => $this->service->submit($input, $this->w->financeB, $preview->previewToken))
        ->toThrow(StaleCorrectionException::class, 'This record changed after you opened it. Please look again.');

    expect(CorrectionLink::count())->toBe(0)->and($voucher->fresh()->isReversed())->toBeFalse();
});

it('15. a token from user A replayed by user B, an expired token, and a token for another payload are refused', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $input = $this->w->input($voucher, DocumentAction::Correct, $this->w->replacement($voucher, ['amount' => '4500']));
    $tokenA = $this->w->preview($input, $this->w->financeB)->previewToken;

    expect(fn () => $this->service->submit($input, $this->w->financeC, $tokenA))->toThrow(PreviewExpiredException::class);

    $other = $this->w->input($voucher, DocumentAction::Correct, $this->w->replacement($voucher, ['amount' => '4400']), key: $input->idempotencyKey);
    expect(fn () => $this->service->submit($other, $this->w->financeB, $tokenA))->toThrow(PreviewExpiredException::class);

    expect(fn () => $this->service->submit($input, $this->w->financeB, 'not-a-token'))->toThrow(PreviewExpiredException::class);

    // 45. Expired: 16 minutes later.
    Carbon::setTestNow(Carbon::now()->addMinutes(16));
    expect(fn () => $this->service->submit($input, $this->w->financeB, $tokenA))->toThrow(PreviewExpiredException::class);

    expect(CorrectionLink::count())->toBe(0);
});

it('16. PreventDuplicateSubmission guards the new route and refuses an identical in-flight submit', function () {
    $route = Route::getRoutes()->getByName('records.act');
    expect(app('router')->gatherRouteMiddleware($route))->toContain(PreventDuplicateSubmission::class);

    $middleware = new PreventDuplicateSubmission;
    $user = (new User)->forceFill(['id' => 77]);
    $make = function () use ($user) {
        $request = Request::create('/records/accounting.income_voucher/1/reverse', 'POST', ['reason_text' => 'Wrong amount', 'idempotency_key' => 'k1']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        return $request;
    };

    $blocked = null;
    $middleware->handle($make(), function () use ($middleware, $make, &$blocked) {
        $blocked = $middleware->handle($make(), fn () => new Response('never'));

        return new Response('first');
    });

    expect($blocked->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

it('48. a changed dependency (matched on the bank after the preview) and a newly found duplicate are refused safely', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $input = $this->w->input($voucher, DocumentAction::Reverse);
    $preview = $this->w->preview($input, $this->w->financeB);

    $this->w->matchOnBankStatement($voucher);

    expect(fn () => $this->service->submit($input, $this->w->financeB, $preview->previewToken))
        ->toThrow(CorrectionBlockedException::class, 'Reverse: matched on the bank statement. Unmatch it first.');

    $target = $this->w->record('9000', '2026-10-15');
    $correct = $this->w->input($target, DocumentAction::Correct, $this->w->replacement($target, ['account_id' => $this->w->interest->id, 'amount' => '4500']));
    $clean = $this->w->preview($correct, $this->w->financeB);
    expect($clean->duplicate)->toBeNull();

    // A similar voucher is saved between the preview and the submit.
    $this->w->record('4500', '2026-10-14', head: $this->w->interest);

    expect(fn () => $this->service->submit($correct, $this->w->financeB, $clean->previewToken))->toThrow(PossibleDuplicateException::class);
    expect($target->fresh()->isReversed())->toBeFalse();
});

it('46. a refused attempt is logged as a blocked attempt after the rollback, and leaves no link', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $this->w->reverse($voucher, $this->w->financeB);

    try {
        $this->w->reverse($voucher->fresh(), $this->w->financeC);
    } catch (CorrectionBlockedException) {
    }

    $log = DB::table('activity_log')->where('log_name', 'corrections')->where('event', 'blocked_attempt')->first();

    expect($log)->not->toBeNull()
        ->and(json_decode($log->properties, true)['codes'])->toContain('already_reversed')
        ->and((int) $log->causer_id)->toBe($this->w->financeC->id)
        ->and(CorrectionLink::count())->toBe(1);
});

it('a JSON caller gets 409 for a stale record and 409 with the candidates for a duplicate', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $this->w->record('4500', '2026-10-14', head: $this->w->interest);
    $base = '/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/correct";
    $body = ['reason_text' => 'It was bank interest', 'idempotency_key' => (string) Str::uuid(),
        'replacement' => $this->w->replacement($voucher, ['account_id' => $this->w->interest->id, 'amount' => '4500'])];

    $preview = $this->actingAs($this->w->financeB)->postJson("{$base}/preview", $body)->assertOk()->json();
    expect($preview['duplicate']['candidates'])->toHaveCount(1);

    $this->actingAs($this->w->financeB)->postJson($base, $body + ['preview_token' => $preview['preview_token']])
        ->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');

    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->w->owner);

    $this->actingAs($this->w->financeB)->postJson($base, $body + ['preview_token' => $preview['preview_token']])
        ->assertStatus(409)->assertJsonPath('code', 'stale');
});
