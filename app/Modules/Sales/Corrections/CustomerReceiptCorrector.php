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
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\Corrections\UndoCustomerReceipt;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Corrections\Checks\ReceiptAllocationCheck;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ A customer receipt's own reversal and successor (corrections wave 1, 20
 * Oct 2026; PHASE-0-CONTRACT 1.4).
 *
 *   reverse()          UndoCustomerReceipt: the journal mirrored on the date the
 *                      rules give, the allocations released (the invoices owe
 *                      again)
 *   createSuccessor()  RecordCustomerReceipt with the edited form: every rule
 *                      the receipt screen has applies again, under its own
 *                      locks (an invoice's outstanding, a courier or gateway
 *                      account refused, the customer's own invoices only)
 *   markOriginal()     the Reversible columns; money fields are never touched
 *
 * ⚠ The successor keeps the original's ORDER only while the customer stays the
 * same: money held for an order is that customer's, never somebody else's.
 */
class CustomerReceiptCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly CustomerReceiptActionPolicy $policy,
        private readonly UndoCustomerReceipt $undo,
        private readonly RecordCustomerReceipt $record,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CustomerReceipt::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $receipt = CustomerReceipt::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()->where('source_type', $receipt->getMorphClass())->where('source_id', $receipt->getKey())->lockForUpdate()->get(['id']);
        $invoiceIds = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->lockForUpdate()->pluck('sales_invoice_id');
        SalesInvoice::query()->whereIn('id', $invoiceIds)->lockForUpdate()->get(['id']);

        return $receipt->load('allocations');
    }

    public function fingerprint(Model $document): string
    {
        /** @var CustomerReceipt $document */
        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $journalIds = array_map(fn ($pair) => $pair[0], $journals);
        $matched = $journalIds === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journalIds)->select('id'))
            ->count();
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'r' => [
                $document->id, $document->customer_id, $document->sales_order_id, $document->money_account_id,
                $document->received_on?->toDateString(), (string) $document->amount, $document->reference,
                $document->note, $document->voucher_id, $document->reversed_at?->toIso8601String(),
            ],
            'allocations' => $document->allocations()->orderBy('id')->get(['id', 'sales_invoice_id', 'amount'])
                ->map(fn ($a) => [(int) $a->id, (int) $a->sales_invoice_id, (string) $a->amount])->all(),
            'journals' => $journals,
            'matched' => $matched,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    /**
     * The receipt screen's own rules, plus what only a correction knows: the
     * original's allocations come off before the new ones go on, so an invoice
     * may take back what the original had on it.
     */
    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var CustomerReceipt $document */
        $businessId = (int) app(BusinessContext::class)->id();

        $data = Validator::make($input, [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.sales_invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ], [
            'received_on.before_or_equal' => 'Money cannot be received on a future date.',
            'allocations.*.sales_invoice_id.distinct' => 'The same invoice is listed twice. Put the whole amount on one line.',
        ])->validate();

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $account = MoneyAccount::query()->findOrFail($data['money_account_id']);

        if ($account->isCourier() || $account->isGateway()) {
            throw ValidationException::withMessages(['money_account_id' => "{$account->name} is reached only through its parcels or payments, never by a receipt typed in."]);
        }

        if (! $account->is_active || $account->account_id === null) {
            throw ValidationException::withMessages(['money_account_id' => "{$account->name} cannot take money now: it is switched off or has no ledger head."]);
        }

        $amount = Money::ofTaka((string) $data['amount']);
        $released = (int) $customer->id === (int) $document->customer_id ? ReceiptAllocationCheck::byInvoice($document) : [];
        $allocations = [];
        $allocated = 0;

        foreach (array_values($data['allocations'] ?? []) as $index => $entry) {
            $value = Money::ofTaka((string) $entry['amount']);

            if ($value->minor <= 0) {
                continue;
            }

            $invoice = SalesInvoice::query()->find((int) $entry['sales_invoice_id']);

            if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages(["allocations.{$index}.sales_invoice_id" => 'That invoice is not one of this customer\'s.']);
            }

            $room = $invoice->outstanding()->minor + ($released[$invoice->id] ?? 0);

            if ($value->minor > $room) {
                throw ValidationException::withMessages(["allocations.{$index}.amount" => "Invoice {$invoice->invoice_number} has only ".Money::ofMinor($room)->format().' to pay.']);
            }

            $allocations[] = ['sales_invoice_id' => (int) $invoice->id, 'amount' => $value->toTaka()];
            $allocated += $value->minor;
        }

        if ($allocated > $amount->minor) {
            throw ValidationException::withMessages(['allocations' => 'The invoices take more than the amount received.']);
        }

        // Money no invoice takes is held for an order, and only the original's
        // order of the same customer can hold it (RecordCustomerReceipt's rule).
        $orderId = (int) $customer->id === (int) $document->customer_id ? $document->sales_order_id : null;

        if ($allocated < $amount->minor && $orderId === null) {
            throw ValidationException::withMessages(['allocations' => 'Put the whole amount on this customer\'s invoices: money no invoice takes must be paid for a named order.']);
        }

        $values = [
            'customer_id' => (int) $customer->id,
            'money_account_id' => (int) $account->id,
            'received_on' => Carbon::parse($data['received_on'])->toDateString(),
            'amount' => $amount->toTaka(),
            'reference' => self::text($data['reference'] ?? null),
            'note' => self::text($data['note'] ?? null),
            'allocations' => $allocations,
            'sales_order_id' => $orderId === null ? null : (int) $orderId,
            'held' => Money::ofMinor($amount->minor - $allocated)->toTaka(),
        ];

        if ($this->sameAs($document, $values)) {
            throw ValidationException::withMessages(['amount' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        return new ReplacementData(Carbon::parse($values['received_on'])->startOfDay(), $amount->minor, $values, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CustomerReceipt $document */
        $document->loadMissing(['moneyAccount', 'customer', 'allocations.invoice:id,invoice_number']);
        $money = $document->moneyAccount?->name ?? 'the money account';
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);

        $lines = [
            new EffectLine(Tk::of($total)." comes out of {$money} on {$on}.", $money, -$total, 'ledger'),
            new EffectLine(Tk::of($total).' goes back on what '.($document->customer?->name ?? 'the customer').' owes.', 'Accounts Receivable', $total, 'ledger'),
        ];

        foreach ($document->allocations as $allocation) {
            $amount = Money::ofTaka((string) $allocation->amount)->minor;
            $lines[] = new EffectLine("Invoice {$allocation->invoice?->invoice_number} is unpaid again by ".Tk::of($amount).'.', null, $amount, 'allocation');
        }

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $values = $replacement->values;
            $newMoney = MoneyAccount::query()->find($values['money_account_id'])?->name ?? 'the money account';
            $customer = Customer::query()->find($values['customer_id'])?->name ?? 'the customer';
            $lines[] = new EffectLine(Tk::of($replacement->totalMinor)." comes into {$newMoney} from {$customer} on ".Tk::date($replacement->date).'.', $newMoney, $replacement->totalMinor, 'ledger');

            foreach ($values['allocations'] as $allocation) {
                $number = SalesInvoice::query()->whereKey($allocation['sales_invoice_id'])->value('invoice_number');
                $lines[] = new EffectLine("It pays invoice {$number} ".Tk::of(Money::ofTaka($allocation['amount'])->minor).'.', null, Money::ofTaka($allocation['amount'])->minor, 'allocation');
            }

            if (Money::ofTaka($values['held'])->minor > 0) {
                $order = SalesOrder::query()->whereKey($values['sales_order_id'])->value('order_number');
                $lines[] = new EffectLine(Tk::of(Money::ofTaka($values['held'])->minor)." is held for order {$order}.", null, null, 'allocation');
            }
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $label = CustomerReceiptActionPolicy::label($document);

        return $action === DocumentAction::Correct
            ? ["{$label} stays, marked Corrected.", "The new receipt is labelled \"Correction of {$label}\"."]
            : ["{$label} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->received_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->amount)->minor;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return self::makerAndLastEditor($document);
    }

    /** @return list<int> the maker and the last person who changed it */
    public static function makerAndLastEditor(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_unique(array_filter([(int) $document->created_by, $lastEditor === null ? null : (int) $lastEditor])));
    }

    public function journalOf(Model $document): ?Journal
    {
        return SalesLedgerCheck::journalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CustomerReceipt $document */
        try {
            return $this->undo->undo(
                $document,
                $ctx->actor,
                $ctx->dates->reversalDate,
                'Reversal of '.CustomerReceiptActionPolicy::label($document).": {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var CustomerReceipt $document */
        $values = $replacement->values;
        $customer = Customer::query()->findOrFail($values['customer_id']);

        try {
            return $this->record->handle(
                customer: $customer,
                moneyAccount: MoneyAccount::query()->findOrFail($values['money_account_id']),
                receivedOn: $values['received_on'],
                allocations: array_map(fn (array $a) => [
                    'invoice' => SalesInvoice::query()->findOrFail($a['sales_invoice_id']),
                    'amount' => $a['amount'],
                ], $values['allocations']),
                createdBy: $ctx->actor,
                reference: $values['reference'],
                note: $values['note'],
                forOrder: $values['sales_order_id'] === null ? null : SalesOrder::query()->find($values['sales_order_id']),
                heldForOrder: $values['held'],
                pass: $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return SalesLedgerCheck::journalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->undo->mark($document, $ctx->actor, $ctx->dates->reversalDate, $ctx->reason->display(), $reversal);
    }

    public function successorClass(): string
    {
        return CustomerReceipt::class;
    }

    /**
     * This type's own invariants: the original is marked and holds no
     * allocation; each receipt's journal moves exactly its amount; a receipt
     * never pays more than it is; no invoice it touched is paid past its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CustomerReceipt $original */
        if (! $original->isReversed() || $original->allocations()->exists()) {
            throw new LogicException('Receipt #'.$original->id.' was not marked and released.');
        }

        foreach (array_filter([$original, $successor]) as $receipt) {
            $journal = Journal::query()->where('source_type', $receipt->getMorphClass())->where('source_id', $receipt->getKey())
                ->whereNull('reverses_journal_id')->orderBy('id')->first();

            if ($journal !== null && $journal->totalDebits()->minor !== $this->totalMinor($receipt)) {
                throw new LogicException('Receipt #'.$receipt->id.'\'s amount does not match its journal.');
            }
        }

        if ($successor !== null) {
            /** @var CustomerReceipt $successor */
            if ($successor->unallocated()->minor < 0) {
                throw new LogicException('Receipt #'.$successor->id.' pays more than it is.');
            }

            $touched = array_unique([...array_keys(ReceiptAllocationCheck::byInvoice($successor)), ...array_column($original->reversed_allocations ?? [], 'sales_invoice_id')]);

            foreach (SalesInvoice::query()->whereIn('id', $touched)->get() as $invoice) {
                if ($invoice->outstanding()->minor < 0) {
                    throw new LogicException("Invoice {$invoice->invoice_number} would be paid past its total.");
                }
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    /** @param array<string, mixed> $values */
    private function sameAs(CustomerReceipt $receipt, array $values): bool
    {
        $before = [
            (int) $receipt->customer_id, (int) $receipt->money_account_id, $receipt->received_on->toDateString(),
            Money::ofTaka((string) $receipt->amount)->minor, self::text($receipt->reference), self::text($receipt->note),
            collect(ReceiptAllocationCheck::byInvoice($receipt))->sortKeys()->all(),
        ];

        $after = [
            $values['customer_id'], $values['money_account_id'], $values['received_on'],
            Money::ofTaka($values['amount'])->minor, $values['reference'], $values['note'],
            collect($values['allocations'])->mapWithKeys(fn ($a) => [$a['sales_invoice_id'] => Money::ofTaka($a['amount'])->minor])->sortKeys()->all(),
        ];

        return $before === $after;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
