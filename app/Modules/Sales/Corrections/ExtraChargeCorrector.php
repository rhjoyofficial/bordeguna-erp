<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Actions\Corrections\RaiseExtraCharge;
use App\Modules\Sales\Actions\PostSalesJournals;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ An extra charge through the engine (24 Oct 2026): RaiseExtraCharge does
 * the domain work (a supplementary invoice of its own, citing the first), as
 * it did from the old form.
 */
class ExtraChargeCorrector extends SalesInvoiceAdditionCorrector
{
    public function __construct(
        private readonly ExtraChargePolicy $policy,
        private readonly RaiseExtraCharge $raise,
        private readonly PostSalesJournals $posting,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    protected function dateField(): string
    {
        return 'charge_date';
    }

    protected function ownRules(): array
    {
        return [
            'description' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function checkTyped(SalesInvoice $invoice, array $data): void
    {
        if (($data['due_date'] ?? null) !== null && $data['due_date'] < $data['charge_date']) {
            throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the charge date.']);
        }
    }

    protected function effects(SalesInvoice $invoice, ReplacementData $replacement): array
    {
        $amount = Tk::of($replacement->totalMinor);

        return [
            new EffectLine("A new invoice of {$amount} for \"{$replacement->values['description']}\" is raised on ".Tk::date($replacement->date).", citing {$invoice->invoice_number}.", null, null, 'document'),
            new EffectLine("{$invoice->customer?->name} owes {$amount} more, and {$amount} goes to sales.", 'Accounts Receivable', $replacement->totalMinor, 'ledger'),
        ];
    }

    protected function issue(SalesInvoice $invoice, ReplacementData $replacement, CommitContext $ctx): Model
    {
        return $this->raise->handle(
            $invoice,
            $replacement->date->toDateString(),
            (string) $replacement->values['amount'],
            (string) $replacement->values['description'],
            $ctx->reason,
            $ctx->actor,
            $replacement->values['due_date'] ?? null,
            $ctx->pass,
        );
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var SalesInvoice $successor */
        return $this->posting->existingJournal($successor);
    }

    public function successorClass(): string
    {
        return SalesInvoice::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        parent::assertInvariants($original, $successor);

        /** @var SalesInvoice $successor */
        if ((int) $successor->debit_note_for_invoice_id !== (int) $original->getKey()) {
            throw new LogicException('The extra charge does not cite the invoice it is on.');
        }
    }
}
