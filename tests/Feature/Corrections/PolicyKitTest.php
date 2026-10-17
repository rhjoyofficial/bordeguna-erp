<?php

use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;

/**
 * The policy test kit (PHASE-0-CONTRACT v2, 1.2 and 3.7 test 40; ChatGPT's 49).
 * Every registered DocumentActionPolicy is walked against a real record of its
 * type, in each state the slice can reach. A module adds its type to the
 * `records` map below when it registers a policy.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<array{0: Illuminate\Database\Eloquent\Model, 1: string}> */
function kitRecords(CorrectionWorld $w): array
{
    $live = $w->record('5000', '2026-10-15');
    $reversed = $w->record('700', '2026-10-15');
    $w->reverse($reversed, $w->financeB);
    $matched = $w->record('900', '2026-10-15');
    $w->matchOnBankStatement($matched);

    return [[$live, 'live'], [$reversed->fresh(), 'reversed'], [$matched, 'bank-matched']];
}

it('40. every registered policy follows the four rules for every user and every record state', function () {
    $registry = app(DocumentPolicyRegistry::class);
    $users = [$this->w->owner, $this->w->financeB, $this->w->manager, $this->w->sales];
    $problems = [];

    foreach ($registry->types() as $type) {
        $policy = $registry->for($type);
        $records = $type === IncomeVoucherActionPolicy::TYPE ? kitRecords($this->w) : [];

        expect($records)->not->toBeEmpty("The policy kit has no record for {$type}: add one.");

        foreach ($records as [$record, $state]) {
            foreach ($users as $user) {
                $set = $policy->actionsFor($record, $user);
                $primary = 0;

                foreach ($set->actions as $availability) {
                    $where = "{$type} {$state} for {$user->name}: {$availability->action->value}";

                    // 1. Edit is never offered on a Done record.
                    if ($set->stage === DocumentStage::Done && $availability->action === DocumentAction::Edit) {
                        $problems[] = "{$where}: Edit offered on a Done record";
                    }

                    // 2. Delete is never offered when the type has an effect.
                    if ($availability->action === DocumentAction::Delete && $policy->effectProfile()->effects !== []) {
                        $problems[] = "{$where}: Delete offered on a type with effects";
                    }

                    // 3. A blocked action keeps its place, with its blockers.
                    if (! $availability->allowed && $availability->blockers === []) {
                        $problems[] = "{$where}: blocked with no reason";
                    }

                    // 4. Every blocker is one plain sentence.
                    foreach ($availability->blockers as $blocker) {
                        if (! preg_match('/^[A-Z0-9].*[.]$/s', $blocker->message) || preg_match('/[A-Z][a-z]+\\\\|::|_[a-z]+_|\bnull\b/', $blocker->message) || substr_count(rtrim($blocker->message, '.'), '. ') > 1) {
                            $problems[] = "{$where}: blocker is not one plain sentence: {$blocker->message}";
                        }
                    }

                    $primary += $availability->primary ? 1 : 0;
                }

                if ($primary > 2) {
                    $problems[] = "{$type} {$state} for {$user->name}: more than two primary buttons";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('49. an effect with no check covering it fails closed', function () {
    $profile = new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Stock], [BankMatchBlocker::class]);

    expect($profile->uncoveredEffects())->toBe([EffectKind::Ledger, EffectKind::Stock]);

    // A policy that declares Stock without a stock check blocks every money action.
    $policy = new class(app(\App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy::class), app(\App\Modules\Foundation\Corrections\SecondPersonRule::class)) extends IncomeVoucherActionPolicy
    {
        public function effectProfile(): EffectProfile
        {
            return new EffectProfile([EffectKind::Ledger, EffectKind::Stock], [\App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck::class]);
        }
    };

    $voucher = $this->w->record();
    $availability = $policy->availability($voucher, $this->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('missing_dependency_check');

    // The real Other income profile is fully covered.
    expect(app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE)->effectProfile()->uncoveredEffects())->toBe([]);
});
