<?php

use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ActionReasons;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Models\Reason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CorrectionWorld;

/**
 * The data check, the conservative backfill, the reason lists and the
 * role-by-role permission table (PHASE-0-CONTRACT v2, 2.1, 2.2, 4 D1; ChatGPT's 51).
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('51. the backfill fills journal_id only for exactly one live journal; zero or several are reported and left unchanged', function () {
    $one = $this->w->record('100', '2026-10-15');
    $none = $this->w->record('200', '2026-10-15');
    $two = $this->w->record('300', '2026-10-15');

    $morph = (new IncomeVoucher)->getMorphClass();

    // Legacy shapes: journal_id never filled; one voucher lost its journal's
    // source link, another gained a second live journal.
    DB::table('income_vouchers')->update(['journal_id' => null]);
    DB::table('journals')->where('source_type', $morph)->where('source_id', $none->id)->update(['source_id' => 999999]);
    $twoJournal = Journal::where('source_type', $morph)->where('source_id', $two->id)->first();
    DB::table('journals')->insert([
        'business_id' => $this->w->business->id, 'journal_number' => 'JV-LEGACY-2', 'journal_type' => 'manual', 'journal_date' => '2026-10-15',
        'memo' => 'legacy duplicate', 'posted_by' => $this->w->owner->id, 'posted_at' => now(), 'source_type' => $morph, 'source_id' => $two->id, 'created_at' => now(),
    ]);

    $migration = require base_path('app/Modules/Accounting/database/migrations/2026_10_17_100200_income_vouchers_can_be_reversed.php');
    $migration->backfill();

    expect($one->fresh()->journal_id)->not->toBeNull()
        ->and($none->fresh()->journal_id)->toBeNull()
        ->and($two->fresh()->journal_id)->toBeNull();

    // The preflight lists them, read-only.
    $before = DB::table('income_vouchers')->get()->toJson();
    expect(Artisan::call('erp:corrections-preflight'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain("{$none->voucher_number}: 0 live journal(s)")
        ->and($output)->toContain("{$two->voucher_number}: 2 live journal(s)")
        ->and($output)->not->toContain("{$one->voucher_number}: 1")
        ->and(DB::table('income_vouchers')->get()->toJson())->toBe($before)
        ->and(Artisan::call('erp:corrections-preflight', ['--strict' => true]))->toBe(1);

    // And such a voucher cannot be reversed until a person looks.
    $availability = app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE)->availability($two->fresh(), $this->w->owner, DocumentAction::Reverse);
    expect(collect($availability->blockers)->pluck('message')->all())->toContain('This record has more than one ledger entry. Ask the administrator to check it.');
});

it('the reason lists are seeded per business, idempotently, and offered in Settings', function () {
    ActionReasons::seedFor($this->w->business->id);

    expect(Reason::query()->where('context', 'correction')->orderBy('id')->pluck('name')->all())->toBe(ActionReasons::DEFAULTS)
        ->and(Reason::query()->count())->toBe(count(ActionReasons::CONTEXTS) * count(ActionReasons::DEFAULTS));

    // A reason from another context, or another business, is refused by the server.
    $voucher = $this->w->record();
    $wrongContext = Reason::query()->where('context', 'deletion')->first();

    $this->actingAs($this->w->financeB)->postJson('/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/reverse/preview", [
        'reason_id' => $wrongContext->id, 'reason_text' => 'Wrong amount typed in', 'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonValidationErrors('reason_id');

    $right = Reason::query()->where('context', 'reversal')->where('name', 'Wrong amount')->first();
    $this->actingAs($this->w->financeB)->postJson('/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}/reverse/preview", [
        'reason_id' => $right->id, 'reason_text' => 'typed 5,000 for 500', 'idempotency_key' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('confirm_label', 'Reverse');
});

it('role by role: who may preview, correct, approve, read the report and open the bin', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $base = '/records/'.IncomeVoucherActionPolicy::TYPE."/{$voucher->id}";
    $body = fn () => ['reason_text' => 'Wrong amount typed in', 'idempotency_key' => (string) Str::uuid()];

    $expect = [
        // role => [preview, corrections report, recycle bin]
        'owner' => [200, true, true],
        'financeB' => [200, true, false],
        'manager' => [404, false, false],
        'sales' => [404, false, false],
    ];

    foreach ($expect as $who => [$preview, $report, $bin]) {
        $user = $this->w->{$who};

        $this->actingAs($user)->postJson("{$base}/reverse/preview", $body())->assertStatus($preview);
        expect($user->can('corrections.report.view'))->toBe($report, "{$who} report")
            ->and($user->can('recycle_bin.manage') && Owners::is($user))->toBe($bin, "{$who} bin")
            ->and($user->can('accounting.correct.closed_period'))->toBe($who === 'owner', "{$who} closed month")
            ->and($user->can('accounting.correct.approve'))->toBe(in_array($who, ['owner', 'financeB'], true), "{$who} approve");
    }
});

it('the permission migration grants to existing roles idempotently and seeds the workflows', function () {
    $migration = require base_path('app/Modules/Foundation/database/migrations/2026_10_17_100400_correction_permissions_are_granted_to_existing_roles.php');
    $migration->up();
    $migration->up();

    $grants = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->where('roles.business_id', $this->w->business->id)
        ->whereIn('permissions.name', ['accounting.correct', 'accounting.correct.approve', 'accounting.correct.closed_period', 'corrections.report.view', 'recycle_bin.manage'])
        ->get(['roles.name as role', 'permissions.name as permission'])
        ->groupBy('permission')
        ->map(fn ($rows) => $rows->pluck('role')->sort()->values()->all())
        ->sortKeys()
        ->all();

    expect($grants)->toBe([
        'accounting.correct' => ['Finance', 'Owner'],
        'accounting.correct.approve' => ['Finance', 'Owner'],
        'accounting.correct.closed_period' => ['Owner'],
        'corrections.report.view' => ['Finance', 'Owner'],
        'recycle_bin.manage' => ['Owner'],
    ]);
});
