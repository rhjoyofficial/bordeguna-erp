<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
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
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Actions\Corrections\UndoSalesReturn;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ A sales return's own reversal (B13, 24 Oct 2026): UndoSalesReturn does the
 * work (journal mirrored, goods out of the same batches, a mirror return that
 * nets every sum, the challan counters back down); this is the engine's handle
 * on it. Correct is not offered, so there is no successor.
 */
class SalesReturnCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly SalesReturnActionPolicy $policy,
        private readonly UndoSalesReturn $undo,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SalesReturn::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $return = SalesReturn::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->getKey())->lockForUpdate()->get(['id']);
        DeliveryLine::query()->whereIn('id', SalesReturnLine::query()->where('sales_return_id', $return->id)->select('delivery_line_id'))->lockForUpdate()->get(['id']);
        SalesInvoice::query()->whereIn('id', $return->allocations()->select('sales_invoice_id'))->lockForUpdate()->get(['id']);

        return $return->load(['lines.movementLinks', 'lines.invoiceLineCredits', 'allocations']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var SalesReturn $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'r' => [$document->id, $document->return_number, (string) $document->credit_total, $document->returned_at?->toDateString(), $document->reversed_at?->toIso8601String()],
            'refunds' => $document->refunds()->orderBy('id')->pluck('id')->all(),
            'allocations' => $document->allocations()->orderBy('id')->get(['id', 'amount'])->map(fn ($a) => [$a->id, (string) $a->amount])->all(),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->orderBy('id')->pluck('id')->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A return is not corrected in place. Reverse it and record the right return from the challan.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SalesReturn $document */
        $document->loadMissing(['lines.deliveryLine.product:id,name', 'lines.movementLinks', 'allocations.invoice:id,invoice_number', 'customer:id,name']);
        $on = Tk::date($dates->reversalDate);
        $lines = [];

        foreach ($document->lines as $line) {
            $back = $line->movementLinks->where('disposition', SalesReturnDisposition::Stock)->sum(fn ($link) => (float) $link->quantity);

            if ($back > 0) {
                $lines[] = new EffectLine(rtrim(rtrim(number_format($back, 4, '.', ''), '0'), '.').' of '.($line->deliveryLine?->product?->name ?? 'a product').' leave stock again, from the same batches.', null, null, 'stock');
            }
        }

        $credit = $this->totalMinor($document);

        if ($credit > 0) {
            $lines[] = new EffectLine(($document->customer?->name ?? 'The customer').' owes '.Tk::of($credit)." again from {$on}.", 'Accounts Receivable', $credit, 'ledger');
        }

        foreach ($document->allocations as $allocation) {
            $amount = Money::ofTaka((string) $allocation->amount)->minor;
            $lines[] = new EffectLine("Invoice {$allocation->invoice?->invoice_number} is unpaid again by ".Tk::of($amount).'.', null, $amount, 'allocation');
        }

        $lines[] = new EffectLine('The challan lines can be returned or billed again.', null, null, 'allocation');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->return_number} stays, marked Reversed.", 'A reversal return with its own number undoes it; the reversal journal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->returned_at->copy()->startOfDay();
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
        /** @var SalesReturn $document */
        try {
            return $this->undo->undo($document, $ctx->actor, $ctx->dates->reversalDate, "Reversal of return {$document->return_number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A sales return has no successor: Correct is not offered.');
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
        return SalesReturn::class;
    }

    /**
     * Marked; one mirror whose credit is exactly the original's negated; no
     * challan line returned below nothing; no invoice paid past its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SalesReturn $original */
        if (! $original->isReversed()) {
            throw new LogicException("Return {$original->return_number} was not marked.");
        }

        $mirrors = SalesReturn::query()->where('reverses_return_id', $original->id)->get();

        if ($mirrors->count() !== 1 || bccomp(bcadd((string) $mirrors->first()->credit_total, (string) $original->credit_total, 4), '0', 4) !== 0) {
            throw new LogicException("Return {$original->return_number} has no single exact mirror.");
        }

        foreach (DeliveryLine::query()->whereIn('id', $original->lines()->select('delivery_line_id'))->get() as $line) {
            if ((float) $line->quantity_returned < -0.00005 || (float) $line->quantity_returned_uninvoiced < -0.00005) {
                throw new LogicException("Return {$original->return_number}: a challan line went below nothing returned.");
            }
        }

        foreach (SalesInvoice::query()->whereIn('id', $original->allocations()->select('sales_invoice_id'))->get() as $invoice) {
            if ($invoice->outstanding()->minor > Money::ofTaka((string) $invoice->total)->minor) {
                throw new LogicException("Invoice {$invoice->invoice_number} would owe more than its total.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }
}
