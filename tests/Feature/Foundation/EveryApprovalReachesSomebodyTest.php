<?php

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;

/**
 * ⚠⚠ WHY THIS FILE EXISTS.
 *
 * The HRM Expense Claim module shipped with a route, a screen, a nav link, a
 * migration and TWELVE GREEN TESTS, and it reached nobody. There was no
 * `expense_claim` row in `approval_workflows`, so `submitForApproval` attached
 * no workflow, the request never carried a stage, the approval inbox lists only
 * what the current stage says the reader may act on, and settlement refuses
 * anything unapproved. The claim saved, the employee was told it had been sent
 * for approval, and it sat Pending for ever. Every screen involved looked like
 * it had worked.
 *
 * ⚠⚠ THE TWELVE TESTS MISSED IT BECAUSE THEY WROTE THE APPROVED STATUS BY HAND.
 * A test that sets `status = Approved` on the approval request proves the code
 * after approval and can never discover that approval is unreachable.
 *
 * ⚠ And `EveryModuleIsReachableTest` could not have caught it either: that guard
 * filters to GET routes, so every approve, reject and settle POST is invisible
 * to it. Reachability of a PAGE and reachability of an ACTION are different
 * questions and needed different guards.
 *
 * ⚠ Deliberately FILE-BASED where it looks at the inbox, for the same reason the
 * sidebar guard is: building a fixture for all sixteen approvable models would
 * be a second implementation of every module, and it would rot. Reading the
 * source fails loudly the day somebody adds an approvable model and forgets the
 * two things that make its approval reach a human.
 *
 * ⚠⚠ This guard found a second one on the day it was written: `sales_order` had
 * no workflow row either, and neither Sales Orders nor Inventory Adjustments had
 * a case in the inbox. Both are fixed in the same commit.
 */

/**
 * Every model that opts into approval, discovered from the source rather than
 * from a list somebody has to remember to extend.
 */
function approvableModels(): array
{
    $found = [];

    foreach (glob(base_path('app/Modules/*/Models/*.php')) as $file) {
        $source = file_get_contents($file);

        // `use Approvable` in the class body, not the import line.
        if (! preg_match('/\n\s*use [^;]*\bApprovable\b[^;]*;/', $source)) {
            continue;
        }

        if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)) {
            continue;
        }

        $found[] = trim($ns[1]).'\\'.basename($file, '.php');
    }

    sort($found);

    return $found;
}

/**
 * Run every workflow seeder in the codebase, found the same way.
 *
 * ⚠ Discovered by filename so a module that adds its own workflow defaults is
 * covered without editing this test. A seeder nobody runs is the same defect as
 * a workflow row nobody creates.
 */
function seedEveryWorkflow(): void
{
    foreach (glob(base_path('app/Modules/*/database/seeders/*WorkflowSeeder.php')) as $file) {
        $source = file_get_contents($file);

        preg_match('/namespace\s+([^;]+);/', $source, $ns);

        $class = trim($ns[1]).'\\'.basename($file, '.php');

        if (class_exists($class)) {
            (new $class)->run();
        }
    }
}

beforeEach(function () {
    Business::create(['name' => 'Guard Co', 'slug' => 'guard-co']);
    seedEveryWorkflow();
});

it('discovers the approvable models rather than trusting a hand-written list', function () {
    // A sanity anchor: if discovery silently matches nothing, every assertion
    // below passes vacuously and this guard becomes decoration.
    expect(approvableModels())->not->toBeEmpty()
        ->and(approvableModels())->toContain(App\Modules\Hrm\Models\ExpenseClaim::class)
        ->and(approvableModels())->toContain(App\Modules\Sales\Models\SalesOrder::class);
});

it('gives every approvable model a workflow with a stage somebody can act on', function () {
    $business = Business::first();
    $broken = [];

    foreach (approvableModels() as $model) {
        // ⚠ ChangeRequest's key lives in a COLUMN, one per kind of change, so a
        // bare instance has no key to check. Its own seeder covers its keys and
        // they are asserted below by name.
        if ($model === App\Modules\Foundation\Models\ChangeRequest::class) {
            continue;
        }

        $key = (new $model)->approvalWorkflowKey();

        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        if ($workflow === null) {
            $broken[] = "{$key}: no active workflow row, so submitForApproval attaches nothing and the request reaches nobody";

            continue;
        }

        $stages = $workflow->stages()->get();

        if ($stages->isEmpty()) {
            $broken[] = "{$key}: workflow exists with no stages, so there is no rule saying who may act";

            continue;
        }

        foreach ($stages as $stage) {
            $roles = $stage->allowed_roles ?? [];
            $permissions = $stage->allowed_permissions ?? [];

            if ($roles === [] && $permissions === []) {
                $broken[] = "{$key}: stage '{$stage->name}' names neither a role nor a permission, so nobody satisfies it";
            }
        }
    }

    expect($broken)->toBe([], "these approvals cannot reach a human:\n".implode("\n", $broken));
});

it('seeds the change request workflows its column-driven keys point at', function () {
    $business = Business::first();

    foreach (['employee_personal_change', 'employee_bank_change'] as $key) {
        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $business->id)
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        expect($workflow)->not->toBeNull("change request key {$key} has no workflow")
            ->and($workflow->stages()->count())->toBeGreaterThan(0);
    }
});

it('gives every approvable model a row in the approval inbox that can be acted on', function () {
    $source = file_get_contents(base_path('app/Http/Controllers/ApprovalInboxController.php'));
    $missing = [];

    foreach (approvableModels() as $model) {
        // ⚠ The inbox matches on the class, so the short name is what appears.
        if (! str_contains($source, 'instanceof '.class_basename($model))) {
            $missing[] = class_basename($model);
        }
    }

    expect($missing)->toBe([], 'these approvable models fall through to the inbox fallback, which renders a row with NO approve or reject link - visible, unactionable, and indistinguishable from having nothing to do: '.implode(', ', $missing));
});

it('keeps the workflow seeders idempotent, because a deploy runs them again', function () {
    $before = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->count();

    seedEveryWorkflow();
    seedEveryWorkflow();

    expect(ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->count())->toBe($before);
});
