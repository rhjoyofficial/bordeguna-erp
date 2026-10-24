<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a fixed asset entered by mistake,
 * reversed (FINAL-PLAN 3.5: "purchase and depreciation reversed under the
 * period rules").
 *
 *   reverse()        ReverseJournal on the purchase entry, on the asset's own
 *                    date (the date the policy gave), with the Owner's pass
 *                    when that month is closed; then ReverseJournal on every
 *                    live depreciation charge, each on ITS own date, so every
 *                    month the asset touched nets to zero
 *   markOriginal()   the Reversible columns; cost and dates are never touched
 *
 * ⚠ The charge ROWS stay: they are the record of what was posted, and the
 * unique (asset, year, month) index keeps the run from charging the month
 * again. The asset is off the register, so nothing reads them as wear.
 *
 * There is no Correct: a wrong asset is reversed and recorded again, the same
 * form the person used the first time.
 */
class FixedAssetCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly FixedAssetActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return FixedAsset::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $asset = FixedAsset::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()
            ->where(fn ($q) => $q->where(fn ($own) => $own->where('source_type', $asset->getMorphClass())->where('source_id', $asset->getKey()))
                ->orWhere(fn ($charges) => $charges->where('source_type', (new FixedAssetDepreciation)->getMorphClass())
                    ->whereIn('source_id', FixedAssetDepreciation::query()->where('fixed_asset_id', $asset->id)->select('id'))))
            ->lockForUpdate()
            ->get(['id']);

        return $asset;
    }

    public function fingerprint(Model $document): string
    {
        /** @var FixedAsset $document */
        $journals = $this->allJournals($document)
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $ids = array_map(fn ($pair) => $pair[0], $journals);

        $matched = $ids === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $ids)->select('id'))
            ->count();

        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->asset_code, $document->acquired_on?->toDateString(), (string) $document->cost,
                (int) $document->account_id, $document->money_account_id, $document->cost_on_books,
                $document->reversed_at?->toIso8601String(), $document->disposed_at?->toIso8601String(),
            ],
            'charges' => FixedAssetDepreciation::query()->where('fixed_asset_id', $document->id)->orderBy('id')
                ->get(['id', 'amount', 'charged_on'])->map(fn ($c) => [$c->id, (string) $c->amount])->all(),
            'journals' => $journals,
            'matched' => $matched,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['correction' => 'A fixed asset is reversed and recorded again, not corrected.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var FixedAsset $document */
        $document->loadMissing(['account', 'moneyAccount']);
        $cost = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);
        $money = $document->moneyAccount?->name ?? 'the account it was paid from';

        $lines = [
            new EffectLine(Tk::of($cost)." goes back into {$money} on {$on}.", $money, $cost, 'ledger'),
            new EffectLine(Tk::of($cost).' comes out of '.($document->account?->name ?? 'the asset account').'.', $document->account?->name, -$cost, 'ledger'),
        ];

        $charges = $this->liveCharges($document);

        if ($charges->isNotEmpty()) {
            $total = $charges->sum(fn (FixedAssetDepreciation $c) => Money::ofTaka((string) $c->amount)->minor);
            $lines[] = new EffectLine(
                $charges->count().' '.($charges->count() === 1 ? 'month' : 'months').' of depreciation ('.Tk::of($total).') '.($charges->count() === 1 ? 'is' : 'are').' reversed, each on its own date.',
                null,
                $total,
                'ledger',
            );
        }

        $lines[] = new EffectLine('It is never depreciated again.');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->asset_code} stays in the register, marked Reversed.", 'Each reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->acquired_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->cost)->minor;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_filter([(int) $document->created_by, $lastEditor === null ? null : (int) $lastEditor]));
    }

    public function journalOf(Model $document): ?Journal
    {
        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->get();

        return $live->count() === 1 ? $live->first() : null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var FixedAsset $document */
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->asset_code} has no single purchase entry to reverse.");
        $memo = "Reversal of {$document->asset_code}: {$ctx->reason->display()}";

        try {
            $reversal = $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, $memo, $ctx->pass);

            // ⚠ Each charge on its own month-end: the month it was charged in
            // nets to zero. No pass: the policy refused a charge in a closed
            // month, because the pass names the asset and not its charges.
            foreach ($this->liveCharges($document) as $charge) {
                $chargeJournal = FixedAssetActionPolicy::liveJournalOf($charge);

                if ($chargeJournal !== null) {
                    $this->reverseJournal->handle($chargeJournal->load('lines'), $ctx->actor, $charge->charged_on->copy()->startOfDay(), "Depreciation reversed with {$document->asset_code}: {$ctx->reason->display()}");
                }
            }

            return $reversal;
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A fixed asset has no successor.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return FixedAsset::class;
    }

    /**
     * This type's own invariant: the asset is marked, its purchase entry is
     * reversed, and no depreciation charge of it is left standing.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var FixedAsset $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->asset_code} was not marked.");
        }

        if ($this->journalOf($original) !== null) {
            throw new LogicException("{$original->asset_code}'s purchase entry is still live.");
        }

        if ($this->liveCharges($original)->isNotEmpty()) {
            throw new LogicException("{$original->asset_code} still has depreciation that was not reversed.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('assets.view');
    }

    /** @return Collection<int, FixedAssetDepreciation> charges whose journal still stands */
    private function liveCharges(FixedAsset $asset): Collection
    {
        return FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $asset->id)
            ->orderBy('charged_on')
            ->get()
            ->filter(fn (FixedAssetDepreciation $charge) => FixedAssetActionPolicy::liveJournalOf($charge) !== null)
            ->values();
    }

    /** @return Collection<int, Journal> every journal of the asset and of its charges */
    private function allJournals(FixedAsset $asset): Collection
    {
        return Journal::query()
            ->where(fn ($q) => $q->where(fn ($own) => $own->where('source_type', $asset->getMorphClass())->where('source_id', $asset->getKey()))
                ->orWhere(fn ($charges) => $charges->where('source_type', (new FixedAssetDepreciation)->getMorphClass())
                    ->whereIn('source_id', FixedAssetDepreciation::query()->where('fixed_asset_id', $asset->id)->select('id'))))
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id']);
    }
}
