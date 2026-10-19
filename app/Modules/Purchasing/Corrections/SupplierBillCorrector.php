<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Corrections\Concerns\CorrectsPurchasingDocuments;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ⭐ A supplier bill's own reversal and successor (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.3 "Supplier bill").
 *
 *   reverse()          ReverseSupplierBill::forCorrection: the journal mirrored
 *                      on the date the date policy gave (the bill's own date by
 *                      default), the invoiced quantity given back to the order
 *                      lines, carriage drafts nobody sent on removed, and the
 *                      bill marked so its number is free for the right one
 *   createSuccessor()  RecordSupplierBill with the edited form: every rule the
 *                      bill screen has (one supplier, the three-way match, the
 *                      delivery limit, service heads, the approval gate) again;
 *                      then "Correct paid bill": the payments and credit notes
 *                      that settled the wrong bill MOVE to the right one. No
 *                      payment is reversed and no money line changes: Accounts
 *                      Payable is per supplier, so only which bill they settle
 *                      changes (ChatGPT ruling 7, FINAL-PLAN 8.1 decision 9)
 *   markOriginal()     done inside reverse() (the number had to be free first)
 *
 * ⚠ The replacement covers what the bill screen records: date, the supplier's
 * number, due date, note, goods lines (order line, delivery, quantity, price
 * or total) and service lines. A trade discount and carriage are their own
 * documents: record them on the corrected bill afterwards.
 */
class SupplierBillCorrector implements DocumentCorrector
{
    use CorrectsPurchasingDocuments;

    public function __construct(
        private readonly SupplierBillActionPolicy $policy,
        private readonly ReverseSupplierBill $reverseBill,
        private readonly RecordSupplierBill $recordBill,
        private readonly PostPurchaseJournals $posting,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SupplierBill::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $bill = SupplierBill::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournals($bill);

        // What settles it, so a payment saved a moment ago waits for us.
        SupplierPaymentAllocation::query()->where('supplier_bill_id', $bill->id)->lockForUpdate()->get(['id']);
        SupplierCreditNoteAllocation::query()->where('supplier_bill_id', $bill->id)->lockForUpdate()->get(['id']);
        PurchaseOrderLine::query()->whereIn('id', $bill->lines()->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id'))->lockForUpdate()->get(['id']);

        return $bill->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SupplierBill $document */
        return hash('sha256', (string) json_encode([
            'b' => [
                $document->id, $document->supplier_id, $document->bill_number, $document->bill_date?->toDateString(), $document->due_date?->toDateString(),
                (string) $document->total, $document->note, $document->reversed_at?->toIso8601String(), $document->purchase_order_id,
            ],
            'lines' => $document->lines()->orderBy('id')->get(['purchase_order_line_id', 'goods_receipt_line_id', 'expense_account_id', 'quantity', 'line_total'])
                ->map(fn ($l) => [$l->purchase_order_line_id, $l->goods_receipt_line_id, $l->expense_account_id, (string) $l->quantity, (string) $l->line_total])->all(),
            'paid' => SupplierPaymentAllocation::query()->where('supplier_bill_id', $document->id)->orderBy('id')->get(['id', 'amount'])->map(fn ($a) => [$a->id, (string) $a->amount])->all(),
            'credited' => SupplierCreditNoteAllocation::query()->where('supplier_bill_id', $document->id)->orderBy('id')->get(['id', 'amount'])->map(fn ($a) => [$a->id, (string) $a->amount])->all(),
            'adjusted' => $document->adjustedTotal()->minor,
            'journals' => $this->journalTrail($document),
            'period' => $this->periodBoundary(),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var SupplierBill $document */
        $this->refuseWhenDone($document);

        $data = Validator::make($input, [
            'supplier_id' => ['nullable', 'integer'],
            'bill_number' => ['required', 'string', 'max:100'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.entry_mode' => ['nullable', Rule::in(['rate', 'total'])],
            'services' => ['nullable', 'array'],
            'services.*.expense_account_id' => ['required', 'integer'],
            'services.*.description' => ['required', 'string', 'max:255'],
            'services.*.amount' => ['required', 'numeric', 'gt:0'],
        ], [
            'due_date.after_or_equal' => 'The due date cannot be before the bill date.',
        ])->validate();

        $lines = array_values($data['lines'] ?? []);
        $services = array_values($data['services'] ?? []);

        if ($lines === [] && $services === []) {
            throw ValidationException::withMessages(['lines' => 'A bill needs at least one line.']);
        }

        $supplierId = (int) ($data['supplier_id'] ?? $document->supplier_id);
        $supplier = Supplier::query()->find($supplierId) ?? throw ValidationException::withMessages(['supplier_id' => 'Pick the supplier.']);
        $settled = $document->paidTotal()->minor + $document->creditedTotal()->minor;

        if ($supplierId !== (int) $document->supplier_id && $settled > 0) {
            throw ValidationException::withMessages(['supplier_id' => "Bill {$document->bill_number} has been paid or credited, so it keeps its supplier. Reverse the payment first to bill another supplier."]);
        }

        $number = trim($data['bill_number']);
        $taken = SupplierBill::query()->where('supplier_id', $supplierId)->where('bill_number', $number)
            ->whereNull('reversed_at')->whereKeyNot($document->id)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['bill_number' => "Bill {$number} has already been recorded for {$supplier->name}."]);
        }

        // What this bill invoiced on each order line goes back when it is
        // reversed, so it is billable again for the corrected bill.
        $own = [];

        foreach ($document->lines as $line) {
            if ($line->purchase_order_line_id !== null) {
                $own[$line->purchase_order_line_id] = ($own[$line->purchase_order_line_id] ?? 0) + PurchaseLineEntry::scaled((string) $line->quantity);
            }
        }

        $totals = [];
        $priceLines = [];
        $wanted = [];
        $goods = [];

        foreach ($lines as $index => $row) {
            $poLine = PurchaseOrderLine::query()->with(['purchaseOrder:id,supplier_id', 'product:id,name'])->find((int) $row['purchase_order_line_id']);

            if ($poLine === null || (int) $poLine->purchaseOrder?->supplier_id !== $supplierId) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Line '.($index + 1).' is not an order line of this supplier.']);
            }

            if (! empty($row['goods_receipt_line_id'])) {
                $receiptLine = GoodsReceiptLine::query()->find((int) $row['goods_receipt_line_id']);

                if ($receiptLine === null || (int) $receiptLine->purchase_order_line_id !== (int) $poLine->id) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Line '.($index + 1).': that delivery is not a receipt of this order line.']);
                }
            }

            try {
                $typed = PurchaseLineEntry::resolve(
                    (string) $row['quantity'],
                    isset($row['unit_cost']) && $row['unit_cost'] !== '' ? (string) $row['unit_cost'] : null,
                    isset($row['line_total']) && $row['line_total'] !== '' ? (string) $row['line_total'] : null,
                    $row['entry_mode'] ?? null,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Line '.($index + 1).': '.$e->getMessage()]);
            }

            $wanted[$poLine->id] = ($wanted[$poLine->id] ?? 0) + PurchaseLineEntry::scaled((string) $row['quantity']);
            $billable = PurchaseLineEntry::scaled((string) $poLine->quantity_received) - PurchaseLineEntry::scaled((string) $poLine->quantity_returned)
                - PurchaseLineEntry::scaled((string) $poLine->quantity_invoiced) + ($own[$poLine->id] ?? 0);

            if ($wanted[$poLine->id] > $billable) {
                throw ValidationException::withMessages(["lines.{$index}" => ($poLine->product?->name ?? 'Line '.($index + 1)).': only '.number_format(max(0, $billable) / 10000, 4, '.', '').' of what arrived can be billed.']);
            }

            $totals[] = $typed['line_total'];
            $goods[] = $typed['line_total'];
            $priceLines[] = ['label' => $poLine->product?->name ?? "Line #{$poLine->id}", 'billed' => $typed['line_total'], 'ordered' => $poLine->valueOf((string) $row['quantity'])];
        }

        foreach ($services as $index => $row) {
            $account = Account::query()->find((int) $row['expense_account_id']);

            if ($account === null) {
                throw ValidationException::withMessages(["services.{$index}" => 'Service line '.($index + 1).': pick an expense head.']);
            }

            $totals[] = Money::ofTaka((string) $row['amount']);
        }

        $total = Money::sum($totals);

        // ⭐ "Correct paid bill": what was paid and credited moves to the
        // corrected bill, so the corrected bill must owe at least that much.
        if ($total->minor < $settled) {
            throw ValidationException::withMessages(['lines' => 'The corrected bill comes to '.Tk::of($total->minor).', less than the '.Tk::of($settled).' already paid or credited on it. Reverse or correct the payment first, or record a supplier credit note.']);
        }

        $date = Carbon::parse($data['bill_date'])->startOfDay();
        $normalised = [
            'supplier_id' => $supplierId,
            'bill_number' => $number,
            'bill_date' => $date->toDateString(),
            'due_date' => $data['due_date'] ?? null,
            'note' => ($data['note'] ?? null) === '' ? null : ($data['note'] ?? null),
            'lines' => array_map(fn (array $row) => [
                'purchase_order_line_id' => (int) $row['purchase_order_line_id'],
                'goods_receipt_line_id' => empty($row['goods_receipt_line_id']) ? null : (int) $row['goods_receipt_line_id'],
                'quantity' => (string) $row['quantity'],
                'unit_cost' => isset($row['unit_cost']) && $row['unit_cost'] !== '' ? (string) $row['unit_cost'] : null,
                'line_total' => isset($row['line_total']) && $row['line_total'] !== '' ? (string) $row['line_total'] : null,
                'entry_mode' => $row['entry_mode'] ?? null,
            ], $lines),
            'services' => array_map(fn (array $row) => [
                'expense_account_id' => (int) $row['expense_account_id'],
                'description' => trim((string) $row['description']),
                'amount' => (string) $row['amount'],
            ], $services),
        ];

        if ($this->sameAs($document, $normalised, $total)) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        // ⭐ Purchasing approvals (ruling P7): a corrected bill that would need
        // a second person cannot go through on one person's click. Above the
        // corrections limit a second person approves anyway (and must be a
        // purchasing approver); below it the bill is reversed and recorded
        // again on the bill screen, which sends it for approval.
        $reasons = [];

        if (PurchasingApprovalGate::enabled()) {
            $reasons = app(PurchasingApprovalGate::class)->assess(new PurchaseDraft(
                kind: 'bill',
                supplier: $supplier,
                amount: $total,
                documentDate: $date,
                reference: $number,
                summary: "Corrected bill {$number} from {$supplier->name}",
                nonPoLines: count($services),
                priceLines: $priceLines,
                discountBase: Money::sum($goods),
            ));

            $basis = $this->secondPerson->basis($this->totalMinor($document), $total->minor);

            if ($reasons !== [] && ! $this->secondPerson->applies((int) $document->business_id, $basis)) {
                throw ValidationException::withMessages(['lines' => 'The corrected bill needs a purchasing approval ('.implode('; ', array_column($reasons, 'label')).'). Reverse this bill and record the right one under Purchases, Bills, so it goes for approval.']);
            }
        }

        return new ReplacementData($date, $total->minor, $normalised + ['approval_reasons' => array_column($reasons, 'label')], $input);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SupplierBill $document */
        $document->loadMissing('supplier:id,name');
        $total = $this->totalMinor($document);
        $supplier = $document->supplier?->name ?? 'the supplier';
        $on = Tk::date($dates->reversalDate);

        $lines = [
            new EffectLine(Tk::of($total)." comes off Accounts Payable for {$supplier} on {$on}, and the goods on it can be billed again.", 'Accounts Payable', $total, 'ledger'),
        ];

        if ($replacement === null) {
            return $lines;
        }

        $values = $replacement->values;
        $newSupplier = (int) $values['supplier_id'] === (int) $document->supplier_id ? $supplier : (Supplier::query()->find($values['supplier_id'])?->name ?? 'the supplier');
        $lines[] = new EffectLine("Bill {$values['bill_number']} of ".Tk::of($replacement->totalMinor)." is owed to {$newSupplier} from ".Tk::date($replacement->date).'.', 'Accounts Payable', -$replacement->totalMinor, 'ledger');

        $paid = $document->paidTotal()->minor;
        $credited = $document->creditedTotal()->minor;

        if ($paid + $credited > 0) {
            $lines[] = new EffectLine('The '.Tk::of($paid + $credited).' already paid or credited moves to the corrected bill. No payment is reversed and no bank line changes.', null, $paid + $credited, 'allocation');
        }

        if (($values['approval_reasons'] ?? []) !== []) {
            $lines[] = new EffectLine('The second person must be a purchasing approver: the corrected bill needs one ('.implode('; ', $values['approval_reasons']).').');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        /** @var SupplierBill $document */
        $number = "Bill {$document->bill_number}";

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", 'The corrected bill is a new record, labelled "Correction of '.$number.'".', "Its attachments stay on {$number}."]
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
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
        return $this->makerAndLastEditor($document);
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var SupplierBill $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SupplierBill $document */
        try {
            return $this->reverseBill->forCorrection(
                $document,
                $ctx->actor,
                $ctx->dates->reversalDate,
                $ctx->reason->display(),
                $ctx->pass,
                // A correction moves what settled the bill; a reversal never does.
                settlementsMove: $ctx->dates->successorDate !== null,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        } catch (DomainException|RuntimeException $e) {
            throw ValidationException::withMessages(['correction' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var SupplierBill $document */
        $values = $replacement->values;
        $supplier = Supplier::query()->findOrFail($values['supplier_id']);

        $lines = array_map(fn (array $row) => array_filter([
            'line' => PurchaseOrderLine::query()->findOrFail($row['purchase_order_line_id']),
            'receiptLine' => $row['goods_receipt_line_id'] === null ? null : GoodsReceiptLine::query()->findOrFail($row['goods_receipt_line_id']),
            'quantity' => $row['quantity'],
            'unitCost' => $row['unit_cost'],
            'lineTotal' => $row['line_total'],
            'entryMode' => $row['entry_mode'],
        ], fn ($value) => $value !== null), $values['lines']);

        $services = array_map(fn (array $row) => [
            'account' => Account::query()->findOrFail($row['expense_account_id']),
            'description' => $row['description'],
            'amount' => $row['amount'],
        ], $values['services']);

        // The original's order, when every goods line still belongs to it.
        $orderIds = array_values(array_unique(array_map(fn (array $row) => (int) $row['line']->purchase_order_id, $lines)));
        $order = $document->purchase_order_id !== null && $orderIds === [(int) $document->purchase_order_id]
            ? PurchaseOrder::query()->find($document->purchase_order_id)
            : null;

        $record = fn () => $this->recordBill->handle(
            supplier: $supplier,
            billNumber: $values['bill_number'],
            billDate: $values['bill_date'],
            lines: $lines,
            createdBy: $ctx->actor,
            purchaseOrder: $order,
            dueDate: $values['due_date'],
            note: $values['note'],
            expenseLines: $services,
            // Ruling P6: the corrected bill keeps the terms the bill was
            // recorded on; a due date that differs is explained by the reason.
            terms: (int) $values['supplier_id'] === (int) $document->supplier_id ? PaymentTerms::forBill($document) : null,
            dueDateReason: $ctx->reason->display(),
            pass: $ctx->pass,
        );

        try {
            $bill = $this->underPurchasingApproval($record, $ctx->actor, $ctx->approver, 'bill');
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['bill_date' => $e->getMessage()]);
        } catch (InvalidArgumentException|DomainException|RuntimeException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $this->moveSettlements($document, $bill, $ctx->actor);

        return $bill;
    }

    /**
     * ⭐ "Correct paid bill": the payments and credit notes that settled the
     * wrong bill now settle the corrected one. Only the allocation rows move;
     * each payment's and credit note's journal is untouched, because Accounts
     * Payable is kept per supplier and the supplier is the same.
     */
    private function moveSettlements(SupplierBill $from, SupplierBill $to, User $by): void
    {
        $payments = SupplierPaymentAllocation::query()->where('supplier_bill_id', $from->id)->get();
        $credits = SupplierCreditNoteAllocation::query()->where('supplier_bill_id', $from->id)->get();

        if ($payments->isEmpty() && $credits->isEmpty()) {
            return;
        }

        $moved = Money::sum([...$payments->map(fn ($a) => Money::ofTaka((string) $a->amount)), ...$credits->map(fn ($a) => Money::ofTaka((string) $a->amount))]);

        if ($moved->minor > Money::ofTaka((string) $to->total)->minor) {
            throw ValidationException::withMessages(['lines' => 'The corrected bill comes to '.Tk::of(Money::ofTaka((string) $to->total)->minor).', less than the '.Tk::of($moved->minor).' already paid or credited on it.']);
        }

        SupplierPaymentAllocation::query()->whereKey($payments->modelKeys())->update(['supplier_bill_id' => $to->id]);
        SupplierCreditNoteAllocation::query()->whereKey($credits->modelKeys())->update(['supplier_bill_id' => $to->id]);

        activity()->performedOn($to)->causedBy($by)
            ->withProperties([
                'from_bill' => $from->id,
                'payments' => $payments->map(fn ($a) => ['payment_id' => $a->supplier_payment_id, 'amount' => (string) $a->amount])->all(),
                'credit_notes' => $credits->map(fn ($a) => ['credit_note_id' => $a->supplier_credit_note_id, 'amount' => (string) $a->amount])->all(),
            ])
            ->log("Payments and credits moved here from bill {$from->bill_number}");
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var SupplierBill $successor */
        return $this->posting->existingJournal($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // Marked inside reverse(): the number had to be free before the
        // corrected bill was recorded (PUR-004).
    }

    public function successorClass(): string
    {
        return SupplierBill::class;
    }

    /**
     * This type's own invariant: the original is marked and settles nothing;
     * its journal is mirrored; every bill's total is what its journal credits
     * to Accounts Payable; the corrected bill is never settled beyond its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SupplierBill $original */
        if (! $original->isReversed()) {
            throw new LogicException("Bill {$original->bill_number} was not marked.");
        }

        if ($original->paidTotal()->minor !== 0 || $original->creditedTotal()->minor !== 0) {
            throw new LogicException("Bill {$original->bill_number} still carries a payment or a credit.");
        }

        foreach (array_filter([$original, $successor]) as $bill) {
            /** @var SupplierBill $bill */
            $journal = $this->posting->existingJournal($bill);

            if ($journal === null) {
                continue;
            }

            $credited = -(int) $journal->lines()->where('account_id', $this->payableAccountId($journal))->sum('amount_minor');

            if ($credited !== Money::ofTaka((string) $bill->total)->minor) {
                throw new LogicException("Bill {$bill->bill_number}'s total does not match what its journal owes.");
            }
        }

        if ($successor !== null && $successor->paidTotal()->minor + $successor->creditedTotal()->minor > Money::ofTaka((string) $successor->total)->minor) {
            throw new LogicException("Bill {$successor->bill_number} is settled beyond its total.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** The Accounts Payable head on the journal's date. */
    private function payableAccountId(Journal $journal): int
    {
        return AccountMapping::resolve('purchasing.accounts_payable', Carbon::parse($journal->journal_date->toDateString()))->id;
    }

    /** @param array<string, mixed> $after */
    private function sameAs(SupplierBill $bill, array $after, Money $total): bool
    {
        $norm = fn ($v) => $v === '' ? null : $v;

        if ((int) $after['supplier_id'] !== (int) $bill->supplier_id || $after['bill_number'] !== $bill->bill_number
            || $after['bill_date'] !== $bill->bill_date?->toDateString() || $norm($after['note']) !== $norm($bill->note)
            || ($after['due_date'] !== null && $after['due_date'] !== $bill->due_date?->toDateString())
            || $total->minor !== Money::ofTaka((string) $bill->total)->minor) {
            return false;
        }

        $before = $bill->lines->map(fn (SupplierBillLine $l) => $l->isExpense()
            ? ['s', (int) $l->expense_account_id, Money::ofTaka((string) $l->line_total)->minor, $norm($l->description)]
            : ['g', (int) $l->purchase_order_line_id, $l->goods_receipt_line_id === null ? null : (int) $l->goods_receipt_line_id, PurchaseLineEntry::scaled((string) $l->quantity), Money::ofTaka((string) $l->line_total)->minor])
            ->sort()->values()->all();

        $now = collect($after['lines'])->map(fn (array $row) => ['g', $row['purchase_order_line_id'], $row['goods_receipt_line_id'], PurchaseLineEntry::scaled($row['quantity']),
            PurchaseLineEntry::resolve($row['quantity'], $row['unit_cost'], $row['line_total'], $row['entry_mode'])['line_total']->minor])
            ->concat(collect($after['services'])->map(fn (array $row) => ['s', $row['expense_account_id'], Money::ofTaka($row['amount'])->minor, $norm($row['description'])]))
            ->sort()->values()->all();

        return $before === $now;
    }
}
