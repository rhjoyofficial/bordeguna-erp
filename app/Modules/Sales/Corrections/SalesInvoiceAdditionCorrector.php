<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\CountsClosedPeriodJournals;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\Contracts\LeavesOriginalLive;
use App\Modules\Foundation\Corrections\Contracts\ResolvesCorrectionDates;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ The engine side of a price credit or an extra charge on an issued sales
 * invoice (corrections wave 1 gaps, 24 Oct 2026; SalesInvoiceAdditionPolicy).
 *
 *   reverse()          nothing: the invoice is never reversed or changed
 *   createSuccessor()  the module's own action issues the new document
 *                      (IssuePriceCredit / RaiseExtraCharge), with the Owner's
 *                      pass when its date is in a closed month
 *   markOriginal()     nothing: the invoice stays live (LeavesOriginalLive),
 *                      and may receive more of them later
 *
 * Its own date rule (ResolvesCorrectionDates): the typed date is the date
 * that matters. Not before the invoice, not in the future. In an open month
 * it posts there; in a closed month it is the Owner's one-document exception
 * for that day (one journal: CountsClosedPeriodJournals), and anyone else may
 * send it to the Owner.
 *
 * ⚠ The fingerprint changes with every credit note and extra charge issued on
 * the invoice, so a second dialog opened before the first was saved is
 * refused as stale: a double click cannot issue two (and the idempotency key
 * stops a retry of the same dialog).
 */
abstract class SalesInvoiceAdditionCorrector implements CountsClosedPeriodJournals, DocumentCorrector, LeavesOriginalLive, ResolvesCorrectionDates
{
    /** The date field of this kind's form: credit_date or charge_date. */
    abstract protected function dateField(): string;

    /** @return array<string, mixed> validation rules for the kind's own fields (the date and amount are shared) */
    abstract protected function ownRules(): array;

    /** Kind-specific refusals on the typed values. */
    abstract protected function checkTyped(SalesInvoice $invoice, array $data): void;

    /** @return list<EffectLine> */
    abstract protected function effects(SalesInvoice $invoice, ReplacementData $replacement): array;

    abstract protected function issue(SalesInvoice $invoice, ReplacementData $replacement, CommitContext $ctx): Model;

    public function find(int $documentId): ?Model
    {
        return SalesInvoice::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        return SalesInvoice::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    public function fingerprint(Model $document): string
    {
        /** @var SalesInvoice $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'i' => [$document->id, $document->reversed_at?->toIso8601String(), (string) $document->total, $document->outstanding()->minor],
            'credits' => $document->creditNotes()->orderBy('id')->pluck('id')->all(),
            'charges' => $document->debitNotes()->orderBy('id')->pluck('id')->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var SalesInvoice $document */
        $field = $this->dateField();
        $data = Validator::make($input, [
            $field => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            ...$this->ownRules(),
        ], [
            "{$field}.before_or_equal" => 'A record cannot be dated in the future.',
        ], [
            $field => 'date',
        ])->validate();

        $date = Carbon::parse($data[$field])->startOfDay();

        if ($date->lt($document->invoice_date->copy()->startOfDay())) {
            throw ValidationException::withMessages([$field => "It cannot be dated before its invoice ({$document->invoice_date->format('d/m/Y')})."]);
        }

        $this->checkTyped($document, $data);

        return new ReplacementData($date, (int) round((float) $data['amount'] * 100), $data, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    /**
     * The typed date is the one that matters (FINAL-PLAN 4.2, "Credit note in
     * the current period"): open, it posts there; closed, the Owner's
     * one-document exception for that day.
     */
    public function correctionDates(Model $document, DocumentAction $action, ReverseDateChoice $choice, ?Carbon $successorDate, ?User $authority): CorrectionDates
    {
        $date = ($successorDate ?? Carbon::today())->copy()->startOfDay();
        $lock = AccountingPeriod::current();
        $closedThrough = $lock !== null && ! $lock->is_unlocked ? $lock->closed_through->copy()->startOfDay() : null;
        $closed = $closedThrough !== null && $date->lessThanOrEqualTo($closedThrough);
        $sentence = 'Posts on '.Tk::date($date).', the date you chose.';

        $make = fn (string $rule, string $text, bool $pass, ?Blocker $blocker) => new CorrectionDates(
            reversalDate: $date,
            successorDate: $date,
            rule: $rule,
            needsClosedPeriodPass: $pass,
            closedThrough: $closedThrough,
            sentence: $text,
            blocker: $blocker,
            closedDates: $pass ? [$date->toDateString()] : [],
        );

        if ($action !== DocumentAction::Correct) {
            return $make('as_dated', '', false, new Blocker('not_offered', "{$action->label()} is not offered here."));
        }

        if ($date->isAfter(Carbon::today())) {
            return $make('as_dated', '', false, new Blocker('future_date', 'A record cannot be dated in the future.'));
        }

        if (! $closed) {
            return $make('as_dated', $sentence, false, null);
        }

        $month = $date->format('F Y');
        $text = $sentence." {$month} is closed: this posts into {$month} as a one-document exception. The month stays closed for everything else.";

        return Owners::mayCorrectClosedMonth($authority)
            ? $make('closed_period_exception', $text, true, null)
            : $make('closed_period_exception', $text, true, new Blocker('period_closed_not_owner', "{$month} is closed. Only the Owner can post into a closed month.", resolvableBy: 'owner', requestable: true));
    }

    /** One journal, the new document's, on the closed day. */
    public function closedPeriodJournals(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        return [1, 1];
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SalesInvoice $document */
        return $replacement === null ? [] : $this->effects($document, $replacement);
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->invoice_number} stays exactly as it was issued."];
    }

    public function originalDate(Model $document): Carbon
    {
        // The credit note a price credit issued is dated its own credit date.
        return $document instanceof SalesCreditNote
            ? Carbon::parse($document->credit_date)->startOfDay()
            : $document->invoice_date->copy()->startOfDay();
    }

    /**
     * ⚠ Zero: the invoice itself moves nothing. The Tk 20,000 rule reads
     * max(original, new), so it is judged on the new document's amount alone.
     */
    public function totalMinor(Model $document): int
    {
        return 0;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_filter([(int) $document->created_by]));
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        return null;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var SalesInvoice $document */
        return $this->issue($document, $replacement, $ctx);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // The invoice stays exactly as issued.
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SalesInvoice $original */
        if ($successor === null) {
            throw new LogicException('Nothing was issued.');
        }

        if ($original->isReversed()) {
            throw new LogicException("{$original->invoice_number} was changed.");
        }

        if ($original->outstanding()->minor < 0) {
            throw new LogicException("{$original->invoice_number} would owe less than nothing.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy()->canView($document, $user);
    }

    abstract public function policy(): DocumentActionPolicy;
}
