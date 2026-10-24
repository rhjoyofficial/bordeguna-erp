<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
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
use App\Modules\Sales\Actions\Corrections\UndoCustomerAdvanceDocument;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ The customer advance documents' own reversal (B11, 24 Oct 2026), the
 * engine's handle on UndoCustomerAdvanceDocument. Correct is not offered, so
 * there is no successor (the right one is entered again where it came from).
 */
abstract class CustomerAdvanceDocumentCorrector implements DocumentCorrector
{
    public function __construct(
        protected readonly CustomerAdvanceDocumentPolicy $policy,
        protected readonly UndoCustomerAdvanceDocument $undo,
    ) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** "Advance", "Advance use", "Advance refund": the words a reader sees. */
    abstract protected function noun(): string;

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return $this->modelClass()::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        /** @var CustomerAdvance|CustomerAdvanceApplication|CustomerAdvanceRefund $document */
        $document = $this->modelClass()::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        // The customer first, as every advance action takes it, then what hangs on it.
        Customer::query()->whereKey($document->customer_id)->lockForUpdate()->first(['id']);
        Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->lockForUpdate()->get(['id']);

        if ($document instanceof CustomerAdvance) {
            CustomerAdvanceApplication::query()->where('customer_advance_id', $document->id)->lockForUpdate()->get(['id']);
            CustomerAdvanceRefund::query()->where('customer_advance_id', $document->id)->lockForUpdate()->get(['id']);
        }

        if ($document instanceof CustomerAdvanceApplication) {
            SalesInvoice::query()->whereKey($document->sales_invoice_id)->lockForUpdate()->first(['id']);
        }

        return $document;
    }

    public function fingerprint(Model $document): string
    {
        $journals = Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
            ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])->all();
        $journalIds = array_map(fn ($pair) => $pair[0], $journals);
        $matched = $journalIds === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journalIds)->select('id'))
            ->count();
        $lock = AccountingPeriod::current();

        $uses = $document instanceof CustomerAdvance
            ? [
                $document->applications()->orderBy('id')->get(['id', 'reversed_at'])->map(fn ($a) => [$a->id, $a->reversed_at?->toIso8601String()])->all(),
                $document->refunds()->orderBy('id')->get(['id', 'reversed_at'])->map(fn ($r) => [$r->id, $r->reversed_at?->toIso8601String()])->all(),
            ]
            : [];

        return hash('sha256', (string) json_encode([
            'd' => [$document->getKey(), $document->number, (string) $document->amount, $document->customer_id, $document->reversed_at?->toIso8601String()],
            'uses' => $uses,
            'journals' => $journals,
            'matched' => $matched,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => "{$this->noun()} {$document->number} is not corrected in place. Reverse it and enter the right one."]);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return CustomerReceiptCorrector::makerAndLastEditor($document);
    }

    public function journalOf(Model $document): ?Journal
    {
        return SalesLedgerCheck::journalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CustomerAdvance|CustomerAdvanceApplication|CustomerAdvanceRefund $document */
        try {
            return $this->undo->undo($document, $ctx->actor, $ctx->dates->reversalDate, "Reversal of {$document->number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException("{$this->noun()} has no successor: Correct is not offered.");
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->undo->mark($document, $ctx->actor, $ctx->dates->reversalDate, $ctx->reason->display(), $reversal);
    }

    public function successorClass(): string
    {
        return $this->modelClass();
    }

    /**
     * Marked; its journal mirrored exactly once; and the customer's advances
     * still add up: nothing open below zero, no invoice paid past its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CustomerAdvance|CustomerAdvanceApplication|CustomerAdvanceRefund $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $live = SalesLedgerCheck::liveJournals($original)->count();

        if ($live !== 0) {
            throw new LogicException("{$original->number} still has a live journal.");
        }

        $customer = Customer::query()->findOrFail($original->customer_id);

        foreach (CustomerAdvance::query()->where('customer_id', $customer->id)->live()->get() as $advance) {
            if ($advance->open()->minor < 0) {
                throw new LogicException("Advance {$advance->number} would be used past its amount.");
            }
        }

        if ($original instanceof CustomerAdvanceApplication) {
            if (PartyAdjustmentAllocation::query()->where('party_adjustment_id', $original->party_adjustment_id)->exists()) {
                throw new LogicException("{$original->number} still reduces its invoice.");
            }

            if (SalesInvoice::query()->findOrFail($original->sales_invoice_id)->outstanding()->minor < 0) {
                throw new LogicException("{$original->number}: its invoice would be paid past its total.");
            }
        }

        if ($original instanceof CustomerAdvance && $original->isFromOrder() && $original->salesOrder?->advanceHeld()->minor < 0) {
            throw new LogicException("{$original->number}: its order would hold less than nothing.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    /** The sentences every type shares: the money or the advance going back. */
    protected function ledgerLines(Model $document, CorrectionDates $dates, string $account, string $other, string $otherAccount): array
    {
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);
        $customer = Customer::query()->whereKey($document->customer_id)->value('name') ?? 'the customer';

        return [
            new EffectLine(Tk::of($total)." {$other} on {$on}.", $otherAccount, null, 'ledger'),
            new EffectLine("{$customer}'s advance with us ".($account === 'up' ? 'goes up' : 'goes down').' by '.Tk::of($total).'.', 'Advances from Customers', $account === 'up' ? $total : -$total, 'ledger'),
        ];
    }
}
