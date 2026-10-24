<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): a write-off, discount or rounding
 * reversed and KEPT.
 *
 *   reverse()       ReverseJournal on its one journal, on the date the date
 *                   policy gave (its own date by default)
 *   markOriginal()  the Reversible columns, and its allocations RELEASED: kept
 *                   on the adjustment as `reversed_allocations`, the rows gone,
 *                   so every invoice and bill it settled owes again and every
 *                   reader (outstanding, ageing, statements, the cockpit, the
 *                   cash forecast) sees it without knowing about reversals
 */
class PartyAdjustmentCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly PartyAdjustmentActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /**
     * Releases the adjustment's allocations and returns what they were. Also
     * used when a journal voucher that made the adjustment is reversed.
     *
     * @return list<array{sales_invoice_id: ?int, supplier_bill_id: ?int, number: ?string, amount: string}>
     */
    public static function releaseAllocations(PartyAdjustment $adjustment): array
    {
        $allocations = PartyAdjustmentAllocation::query()
            ->where('party_adjustment_id', $adjustment->getKey())
            ->with(['invoice:id,invoice_number', 'bill:id,bill_number'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $released = $allocations->map(fn (PartyAdjustmentAllocation $a) => [
            'sales_invoice_id' => $a->sales_invoice_id === null ? null : (int) $a->sales_invoice_id,
            'supplier_bill_id' => $a->supplier_bill_id === null ? null : (int) $a->supplier_bill_id,
            'number' => $a->invoice?->invoice_number ?? $a->bill?->bill_number,
            'amount' => Money::ofTaka((string) $a->amount)->toTaka(),
        ])->values()->all();

        PartyAdjustmentAllocation::query()->whereKey($allocations->modelKeys())->delete();

        return $released;
    }

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return PartyAdjustment::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $adjustment = PartyAdjustment::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($adjustment);

        return $adjustment->load('allocations');
    }

    public function fingerprint(Model $document): string
    {
        /** @var PartyAdjustment $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->kind, $document->adjusted_on?->toDateString(), $document->customer_id, $document->supplier_id,
                (string) $document->amount, $document->voucher_id, $document->reversed_at?->toIso8601String(),
            ],
            'allocations' => $document->allocations()->orderBy('id')->get(['sales_invoice_id', 'supplier_bill_id', 'amount'])
                ->map(fn ($a) => [$a->sales_invoice_id, $a->supplier_bill_id, (string) $a->amount])->all(),
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An adjustment is reversed and recorded again. It is not corrected in one step.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var PartyAdjustment $document */
        $document->loadMissing(['allocations.invoice:id,invoice_number', 'allocations.bill:id,bill_number', 'customer:id,name', 'supplier:id,name']);
        $amount = $document->money()->minor;
        $party = trim(($document->customer?->name ?? '').' '.($document->supplier?->name ?? ''));
        $lines = [new EffectLine("{$document->label()} of ".Tk::of($amount)." for {$party} is undone on ".Tk::date($dates->reversalDate).'.', null, -$amount, 'ledger')];

        foreach ($document->allocations as $allocation) {
            $number = $allocation->invoice?->invoice_number ?? $allocation->bill?->bill_number;
            $minor = Money::ofTaka((string) $allocation->amount)->minor;
            $lines[] = new EffectLine("{$number} owes ".Tk::of($minor).' again.', $number, $minor, 'allocation');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return ["{$number} stays, marked Reversed, with the invoices and bills it had settled.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->adjusted_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var PartyAdjustment $document */
        return $document->money()->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $number = $this->policy->numberOf($document);
        $journal = $this->journalOf($document) ?? throw new LogicException("{$number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of adjustment {$number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A party adjustment has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        /** @var PartyAdjustment $document */
        $released = self::releaseAllocations($document);
        $document->forceFill(['reversed_allocations' => $released])->save();
        $this->markReversed($document, $reversal, $ctx);
    }

    public function successorClass(): string
    {
        return PartyAdjustment::class;
    }

    /** Marked, its journal reversed, and nothing of it left on any invoice or bill. */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        $number = $this->policy->numberOf($original);
        $this->assertReversedBy($original, $number);

        if (PartyAdjustmentAllocation::query()->where('party_adjustment_id', $original->getKey())->exists()) {
            throw new LogicException("{$number} still settles an invoice or a bill.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }
}
