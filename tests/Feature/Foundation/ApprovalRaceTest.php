<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;

/**
 * Two approvers, one request.
 *
 * ⚠⚠ Joy reported this as "an error will happen". The truth was worse: no
 * error happened. The second decision overwrote the status and wrote a second
 * decision row, while the underlying leave or loan stayed in whatever state
 * the FIRST decision had applied, and nothing anywhere said so.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Race Co', 'slug' => 'race-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->first = User::factory()->create(['name' => 'Moin']);
    $this->second = User::factory()->create(['name' => 'Musa']);

    // approvable_* are guarded, so they are set the way the real callers do,
    // through the morph relation rather than mass assignment.
    $this->request = new ApprovalRequest([
        'business_id' => $this->business->id,
        'status' => ApprovalStatus::Pending,
        'current_stage' => 1,
        'requested_by' => $this->first->id,
    ]);
    $this->request->approvable_type = 'test-approvable';
    $this->request->approvable_id = 1;
    $this->request->save();
});

it('refuses a rejection after somebody else has approved', function () {
    // Both approvers hold their own copy, loaded when their page rendered.
    $approverCopy = ApprovalRequest::findOrFail($this->request->id);
    $rejecterCopy = ApprovalRequest::findOrFail($this->request->id);

    $approverCopy->approve($this->first);

    expect(fn () => $rejecterCopy->reject($this->second))
        ->toThrow(ApprovalAlreadyDecidedException::class);

    // The first decision stands, and is the only one.
    expect($this->request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($this->request->fresh()->decisions()->count())->toBe(1);
});

it('refuses an approval after somebody else has rejected', function () {
    $a = ApprovalRequest::findOrFail($this->request->id);
    $b = ApprovalRequest::findOrFail($this->request->id);

    $a->reject($this->second, 'Not this month');

    expect(fn () => $b->approve($this->first))
        ->toThrow(ApprovalAlreadyDecidedException::class);

    expect($this->request->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($this->request->fresh()->decisions()->count())->toBe(1);
});

it('refuses a second approval of the same decision', function () {
    $a = ApprovalRequest::findOrFail($this->request->id);
    $b = ApprovalRequest::findOrFail($this->request->id);

    $a->approve($this->first);

    expect(fn () => $b->approve($this->second))
        ->toThrow(ApprovalAlreadyDecidedException::class);

    expect($this->request->fresh()->decisions()->count())->toBe(1);
});

it('names who decided it, so the message is useful', function () {
    $a = ApprovalRequest::findOrFail($this->request->id);
    $b = ApprovalRequest::findOrFail($this->request->id);

    $a->approve($this->first);

    try {
        $b->reject($this->second);
    } catch (ApprovalAlreadyDecidedException $e) {
        expect($e->getMessage())->toContain('Moin')
            ->and($e->getMessage())->toContain('approved');
    }
});

it('still lets the one approver decide normally', function () {
    $fresh = ApprovalRequest::findOrFail($this->request->id);

    $complete = $fresh->approve($this->first, 'Fine');

    expect($complete)->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($this->request->fresh()->decisions()->count())->toBe(1);
});

it('refuses sending back a request somebody else has already approved', function () {
    $a = ApprovalRequest::findOrFail($this->request->id);
    $b = ApprovalRequest::findOrFail($this->request->id);

    $a->approve($this->first);

    expect(fn () => $b->sendBack($this->second, 'Fix the amount'))
        ->toThrow(ApprovalAlreadyDecidedException::class);

    expect($this->request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($this->request->fresh()->decisions()->count())->toBe(1);
});

// ⚠⚠ Audit 13 Oct: the pending check's row lock only serialises two approvers
// on MySQL while a transaction holds it. Outside one it was released the
// moment the SELECT finished, so both could pass. Pinned by the transaction
// level the check runs at (the lock itself is a no-op on SQLite).
it('runs the pending check and the decision inside one transaction', function () {
    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels) {
        if (str_contains($query->sql, 'approval_request') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $levels[] = DB::transactionLevel();
        }
    });

    foreach (['approve', 'reject', 'sendBack'] as $decision) {
        ApprovalRequest::whereKey($this->request->id)->update(['status' => ApprovalStatus::Pending, 'current_stage' => 1]);
        $copy = ApprovalRequest::findOrFail($this->request->id);
        $levels = [];

        $copy->{$decision}($this->first, 'note');

        expect($levels)->not->toBeEmpty()
            ->and(min($levels))->toBeGreaterThan($base);
    }
});
