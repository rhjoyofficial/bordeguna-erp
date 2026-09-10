<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\ExpenseClaimLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE MIGRATION THAT TURNS EIGHT LIVE CLAIMS INTO ONE-LINE CLAIMS.
 *
 * ⚠⚠ WHY THIS TEST EXISTS, AND IT IS NOT THEORY. The master chart migration in
 * September shipped two defects a green suite could not see, because a SEEDED
 * test only ever describes a FRESH INSTALL and production arrived by
 * restructuring. The lesson written down then was: for any data-restructuring
 * migration, put a row back into its pre-migration shape and run the real
 * migration over it. This does that - it `require`s the migration file and calls
 * `up()` against claims built the way the live ones exist.
 *
 * ⚠ Six of the eight live claims are APPROVED AND POSTED and one is settled.
 * Nothing here may touch an amount, an account or a journal; the line simply
 * says what the header already said.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Backfill Co', 'slug' => 'backfill-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'employee_code' => 'BDG-000031',
        'first_name' => 'Musa', 'last_name' => 'Islam',
        'status' => 'active', 'employment_type' => 'permanent',
    ]);

    Carbon::setTestNow('2026-09-10 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A claim exactly as it existed before 10 Sep 2026: header only, its receipt
 * attached to the CLAIM, and no `submitted_at` because the column did not exist.
 */
function preMigrationClaim(int $businessId, int $employeeId, string $amount, ?int $categoryId = null): ExpenseClaim
{
    $claim = ExpenseClaim::create([
        'business_id' => $businessId,
        'employee_id' => $employeeId,
        'claim_number' => ExpenseClaim::nextNumber($businessId),
        'spent_on' => '2026-09-02',
        'amount' => $amount,
        'purpose' => 'Courier to Savar',
        'expense_category_id' => $categoryId,
    ]);

    DB::table('expense_claims')->where('id', $claim->id)->update([
        'submitted_at' => null,
        'created_at' => '2026-09-02 12:56:00',
        'updated_at' => '2026-09-02 12:56:00',
    ]);

    DB::table('attachments')->insert([
        'business_id' => $businessId,
        'attachable_type' => ExpenseClaim::class,
        'attachable_id' => $claim->id,
        'collection' => ExpenseClaim::EVIDENCE_COLLECTION,
        'disk' => 'local',
        'path' => 'attachments/'.$businessId.'/receipt-'.$claim->id.'.png',
        'original_name' => 'receipt.png',
        'mime_type' => 'image/png',
        'size' => 1024,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $claim->fresh();
}

function runBackfill(): void
{
    (require base_path('app/Modules/Hrm/database/migrations/2026_09_10_100002_backfill_expense_claim_lines.php'))->up();
}

it('turns every existing claim into a one-line claim without touching its figures', function () {
    $first = preMigrationClaim($this->business->id, $this->employee->id, '212.0000');
    $second = preMigrationClaim($this->business->id, $this->employee->id, '547.0000');

    runBackfill();

    foreach ([$first, $second] as $claim) {
        $claim->refresh()->load('lines');
        $line = $claim->lines->sole();

        expect((float) $line->amount)->toBe((float) $claim->amount)
            ->and($line->spent_on->toDateString())->toBe($claim->spent_on->toDateString())
            ->and($line->description)->toBe('Courier to Savar')
            // ⚠ NULL, and it must stay null: these two predate categories, and
            // inventing one would push them onto the posting path and expense
            // money that was never expensed.
            ->and($line->expense_category_id)->toBeNull()
            // ⚠ Submitted when they were CREATED, not today. Every claim before
            // this migration was submitted the moment it was made, because there
            // was no draft to sit in.
            ->and($claim->submitted_at?->toDateTimeString())->toBe('2026-09-02 12:56:00');
    }
});

it('moves the receipt to the line rather than copying it', function () {
    $claim = preMigrationClaim($this->business->id, $this->employee->id, '212.0000');

    runBackfill();

    $line = $claim->fresh()->load('lines')->lines->sole();

    // ⚠⚠ ONE ROW, MOVED. Evidence duplicated is evidence that can disagree with
    // itself: two rows pointing at one file, and a later deletion leaving a
    // dangling one.
    expect(DB::table('attachments')->count())->toBe(1)
        ->and(DB::table('attachments')->where('attachable_type', ExpenseClaimLine::class)
            ->where('attachable_id', $line->id)->exists())->toBeTrue()
        ->and($claim->fresh()->receipts())->toHaveCount(1)
        // The file on disk was never touched: only the row saying who owns it.
        ->and(DB::table('attachments')->value('path'))->toContain('receipt-'.$claim->id);
});

it('can be run twice without doubling a single line', function () {
    $claim = preMigrationClaim($this->business->id, $this->employee->id, '350.0000');

    runBackfill();
    runBackfill();

    expect($claim->fresh()->load('lines')->lines)->toHaveCount(1)
        ->and(DB::table('attachments')->count())->toBe(1);
});

it('leaves a claim that already has lines alone', function () {
    $claim = preMigrationClaim($this->business->id, $this->employee->id, '120.0000');

    $claim->lines()->create([
        'business_id' => $this->business->id,
        'spent_on' => '2026-09-08',
        'amount' => '120',
        'description' => 'Already split out by hand',
        'sort' => 0,
    ]);

    runBackfill();

    expect($claim->fresh()->load('lines')->lines->sole()->description)->toBe('Already split out by hand');
});

it('puts the receipt back on the claim when the migration is rolled back', function () {
    $claim = preMigrationClaim($this->business->id, $this->employee->id, '797.1000');

    runBackfill();
    (require base_path('app/Modules/Hrm/database/migrations/2026_09_10_100002_backfill_expense_claim_lines.php'))->down();

    expect(DB::table('expense_claim_lines')->count())->toBe(0)
        ->and(DB::table('attachments')->where('attachable_type', ExpenseClaim::class)
            ->where('attachable_id', $claim->id)->exists())->toBeTrue();
});
