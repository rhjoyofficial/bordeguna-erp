<?php

use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Foundation\database\seeders\CorrectionWorkflowSeeder;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Symfony\Component\Finder\Finder;
use Tests\Support\CorrectionWorld;

/**
 * Structural guards for edit, delete and correct (PHASE-0-CONTRACT v2, 3.7
 * tests 35 to 38; ChatGPT's 53). A structural test that has never failed is a
 * comment with a longer runtime: each pattern here was checked by putting the
 * defect in and watching it fail.
 */
function correctionSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $stripped = '';

        foreach (token_get_all($file->getContents()) as $token) {
            $stripped .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : (is_array($token) ? $token[1] : $token);
        }

        $files[str_replace('\\', '/', $file->getRelativePathname())] = $stripped;
    }

    return $files;
}

it('35/53. only ClosedPeriodExceptions makes a pass, and only the correction service grants one', function () {
    $offenders = [];

    foreach (correctionSources() as $path => $source) {
        // A fully qualified name counts too (checked by planting `new \App\...\ClosedPeriodPass(`).
        if (preg_match('/new\s+[\\\\A-Za-z_]*ClosedPeriodPass\s*\(/', $source) && $path !== 'Modules/Foundation/Corrections/ClosedPeriodExceptions.php') {
            $offenders[] = "app/{$path} builds a ClosedPeriodPass";
        }

        if (str_contains($source, 'ClosedPeriodExceptions') && preg_match('/->\s*grant\s*\(/', $source)
            && ! in_array($path, ['Modules/Foundation/Corrections/CorrectionService.php', 'Modules/Foundation/Corrections/ClosedPeriodExceptions.php'], true)) {
            $offenders[] = "app/{$path} grants a closed-month exception";
        }

        if (preg_match('/->\s*consume\s*\(\s*\$pass/', $source)
            && ! in_array($path, ['Modules/Accounting/Actions/PostJournal.php', 'Modules/Foundation/Corrections/ClosedPeriodExceptions.php'], true)) {
            $offenders[] = "app/{$path} consumes a pass outside PostJournal";
        }

        // Nothing but the period itself flips is_unlocked: the exception never does.
        if (str_contains($path, 'Corrections/') && preg_match('/is_unlocked\'?\s*=>\s*true|->unlock\s*\(/', $source)) {
            $offenders[] = "app/{$path} unlocks the period";
        }
    }

    expect($offenders)->toBe([], "A closed-month exception has exactly one way in.\n".implode("\n", $offenders));
});

it('53b. nothing outside the correction service marks a voucher reversed or writes a correction link', function () {
    $offenders = [];

    foreach (correctionSources() as $path => $source) {
        $allowed = ['Modules/Foundation/Corrections/CorrectionService.php', 'Modules/Foundation/Models/CorrectionLink.php'];

        if (preg_match('/new\s+CorrectionLink\s*[;(]|CorrectionLink::(create|insert|forceCreate)\s*\(|DB::table\(\s*[\'"]correction_links[\'"]\s*\)\s*->\s*(insert|update|delete)/', $source) && ! in_array($path, $allowed, true)) {
            $offenders[] = "app/{$path} writes a correction link";
        }
    }

    expect($offenders)->toBe([]);
});

it('36. no model is Prunable or MassPrunable, nothing force-deletes a Recyclable model, and evidence is never Recyclable', function () {
    $offenders = [];

    foreach (correctionSources() as $path => $source) {
        if (preg_match('/\buse\s+[^;]*\b(Prunable|MassPrunable)\b[^;]*;/', $source) && str_contains($path, 'Models/')) {
            $offenders[] = "app/{$path} uses a pruning trait";
        }

        if (preg_match('/->\s*forceDelete\s*\(/', $source)) {
            $offenders[] = "app/{$path} force-deletes";
        }
    }

    expect($offenders)->toBe([], "No purge, ever (owner, 17 Oct 2026).\n".implode("\n", $offenders));

    $never = [CorrectionLink::class, CorrectionRequest::class, ClosedPeriodException::class, DuplicateAcknowledgement::class,
        Journal::class, JournalLine::class, IncomeVoucher::class];

    foreach ($never as $class) {
        expect(class_uses_recursive($class))->not->toHaveKey(SoftDeletes::class)
            ->and(class_uses_recursive($class))->not->toHaveKey(Prunable::class)
            ->and(class_uses_recursive($class))->not->toHaveKey(MassPrunable::class)
            ->and(in_array($class, config('recycle_bin.models'), true))->toBeFalse();
    }

    foreach (config('recycle_bin.models') as $class) {
        expect(class_uses_recursive($class))->toHaveKey(Recyclable::class);
    }
});

it('36b. correction evidence refuses to be deleted or changed', function () {
    $w = CorrectionWorld::make();
    $september = $w->record('5000', '2026-09-15');
    $w->closeThrough('2026-09-30');
    $w->reverse($september, $w->owner);

    $link = CorrectionLink::sole();
    $exception = ClosedPeriodException::sole();

    expect(fn () => $link->delete())->toThrow(LogicException::class)
        ->and(fn () => $link->forceFill(['reason_text' => 'rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $exception->delete())->toThrow(LogicException::class)
        ->and(CorrectionLink::count())->toBe(1);

    Carbon::setTestNow();
});

it('37. activitylog:clean is not in the schedule, and the log has no age to delete by', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

    expect($commands)->not->toContain('activitylog:clean')
        ->and(config('activitylog.delete_records_older_than_days'))->toBeNull();
});

it('39. EveryApprovalReachesSomebody discovers CorrectionRequest and both workflows are seeded', function () {
    $source = file_get_contents(app_path('Modules/Foundation/Models/CorrectionRequest.php'));

    expect(preg_match('/\n\s*use [^;]*\bApprovable\b[^;]*;/', $source))->toBe(1)
        ->and(file_exists(app_path('Modules/Foundation/database/seeders/CorrectionWorkflowSeeder.php')))->toBeTrue();

    $business = Business::create(['name' => 'Seed Co', 'slug' => 'seed-co']);
    (new CorrectionWorkflowSeeder)->run();
    (new CorrectionWorkflowSeeder)->run();

    $workflows = ApprovalWorkflow::withoutGlobalScopes()->where('business_id', $business->id)->get()->keyBy('key');

    expect($workflows->keys()->sort()->values()->all())->toBe(['correction', 'correction_closed_period'])
        ->and($workflows['correction']->stages()->count())->toBe(1)
        ->and($workflows['correction']->stageAt(1)->allowed_permissions)->toBe(['accounting.correct.approve'])
        ->and($workflows['correction_closed_period']->stageAt(1)->allowed_roles)->toBe(['Owner'])
        ->and($workflows['correction_closed_period']->stageAt(1)->allowed_permissions)->toBe([]);
});

it('every registered type answers on the generic routes only through the registry', function () {
    $registry = app(DocumentPolicyRegistry::class);

    expect($registry->types())->toContain('accounting.income_voucher');

    foreach ($registry->types() as $type) {
        expect($registry->for($type))->toBeInstanceOf(DocumentActionPolicy::class);
    }
});
