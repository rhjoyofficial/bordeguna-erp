<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Actions\Corrections\IssuePriceCredit;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A price credit note through the engine (24 Oct 2026): IssuePriceCredit
 * does the domain work (CN number, Dr Sales Returns / Cr the customer, the
 * allocation to the invoice), as it did from the old form.
 */
class PriceCreditCorrector extends SalesInvoiceAdditionCorrector
{
    public function __construct(
        private readonly PriceCreditPolicy $policy,
        private readonly IssuePriceCredit $issue,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    protected function dateField(): string
    {
        return 'credit_date';
    }

    protected function ownRules(): array
    {
        return [];
    }

    protected function checkTyped(SalesInvoice $invoice, array $data): void
    {
        $owed = $invoice->outstanding();

        if (Money::ofTaka((string) $data['amount'])->minor > $owed->minor) {
            throw ValidationException::withMessages(['amount' => "{$invoice->invoice_number} owes only {$owed->format()}; for money already paid, reverse or re-allocate the payment instead."]);
        }
    }

    protected function effects(SalesInvoice $invoice, ReplacementData $replacement): array
    {
        $amount = Tk::of($replacement->totalMinor);
        $left = Tk::of($invoice->outstanding()->minor - $replacement->totalMinor);

        return [
            new EffectLine("A price credit note of {$amount} is issued on ".Tk::date($replacement->date).", with its own CN number, citing {$invoice->invoice_number}.", null, null, 'document'),
            new EffectLine("{$amount} goes to Sales Returns and Allowances, and {$invoice->customer?->name} owes {$amount} less: {$invoice->invoice_number} then owes {$left}.", 'Accounts Receivable', -$replacement->totalMinor, 'ledger'),
        ];
    }

    protected function issue(SalesInvoice $invoice, ReplacementData $replacement, CommitContext $ctx): Model
    {
        return $this->issue->handle($invoice, $replacement->date, (string) $replacement->values['amount'], $ctx->reason, $ctx->actor, $ctx->pass);
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var SalesCreditNote $successor */
        return $successor->journal_id === null ? null : Journal::query()->find($successor->journal_id);
    }

    public function successorClass(): string
    {
        return SalesCreditNote::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        parent::assertInvariants($original, $successor);

        /** @var SalesCreditNote $successor */
        if ((int) $successor->sales_invoice_id !== (int) $original->getKey() || $successor->party_adjustment_id === null) {
            throw new LogicException('The credit note does not settle the invoice it cites.');
        }
    }
}
