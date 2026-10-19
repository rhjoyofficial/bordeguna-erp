<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
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
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\Corrections\CreditSalesInvoice;
use App\Modules\Sales\Actions\Corrections\UndoCustomerReceipt;
use App\Modules\Sales\Actions\Corrections\UndoDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Support\DiscountCap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ A sales invoice's own credit note and successor (corrections wave 1,
 * 20 Oct 2026; PHASE-0-CONTRACT 1.4).
 *
 *   reverse()          Credit note: CreditSalesInvoice (the journal mirrored,
 *                      the challans billable again, the invoice marked at once).
 *                      Void sale (a counter sale): the receipts taken back
 *                      first, then the credit note, then the challan reversed
 *                      onto its batches, all in this one transaction.
 *   createSuccessor()  Correct: RecordSalesInvoice with the edited lines, the
 *                      same customer and the same challan lines, under every
 *                      rule the invoice screen has (the price-override
 *                      permission, the per-bill discount caps); then payments
 *                      typed against the old invoice MOVE to the new one (no
 *                      journal: both sit in 1140), refused when they no longer
 *                      fit (the plan's "Correct paid bill" idea, customer side).
 *   markOriginal()     the invoice was already marked inside reverse(): the
 *                      new invoice, created next, must see it gone.
 *
 * ⚠ The issued invoice's printed fields never change (FINAL-PLAN 6.6 rule 1).
 */
class SalesInvoiceCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly SalesInvoiceActionPolicy $policy,
        private readonly CreditSalesInvoice $credit,
        private readonly UndoCustomerReceipt $undoReceipt,
        private readonly UndoDelivery $undoDelivery,
        private readonly RecordSalesInvoice $record,
        private readonly ResolveSellingPrice $prices,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SalesInvoice::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $invoice = SalesInvoice::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $lines = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->lockForUpdate()->get(['id', 'delivery_line_id']);
        DeliveryLine::query()->whereIn('id', $lines->pluck('delivery_line_id')->filter())->lockForUpdate()->get(['id']);
        CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoice->id)->lockForUpdate()->get(['id']);
        Journal::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->getKey())->lockForUpdate()->get(['id']);

        return $invoice->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SalesInvoice $document */
        $lock = AccountingPeriod::current();
        $lines = SalesInvoiceLine::query()->where('sales_invoice_id', $document->id)->orderBy('id')->get();

        return hash('sha256', (string) json_encode([
            'i' => [
                $document->id, $document->invoice_number, $document->customer_id, $document->invoice_date?->toDateString(),
                $document->due_date?->toDateString(), (string) $document->total, (string) $document->invoice_discount,
                $document->note, $document->reversed_at?->toIso8601String(),
            ],
            'lines' => $lines->map(fn ($l) => [$l->id, $l->delivery_line_id, (string) $l->quantity, (string) $l->unit_price, (string) $l->line_discount, $l->live_delivery_for_order_id])->all(),
            'billable' => DeliveryLine::query()->whereIn('id', $lines->pluck('delivery_line_id')->filter())->orderBy('id')
                ->get(['id', 'quantity_invoiced', 'quantity_returned'])->map(fn ($d) => [$d->id, (string) $d->quantity_invoiced, (string) $d->quantity_returned])->all(),
            'paid' => CustomerReceiptAllocation::query()->where('sales_invoice_id', $document->id)->orderBy('id')->get(['id', 'customer_receipt_id', 'amount'])
                ->map(fn ($a) => [$a->id, $a->customer_receipt_id, (string) $a->amount])->all(),
            'adjusted' => DB::table('party_adjustment_allocations')->where('sales_invoice_id', $document->id)->orderBy('id')->pluck('id')->all(),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->orderBy('id')->pluck('id')->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    /**
     * The edited invoice: the same customer and challan lines, a new date,
     * quantities, prices and discounts. The delivery charge an order agreed is
     * billed again by itself, so it is not on the form.
     */
    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var SalesInvoice $document */
        $data = Validator::make($input, [
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ], [
            'invoice_date.before_or_equal' => 'An invoice cannot be dated in the future.',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
        ])->validate();

        // ⚠ The contract hands this method no actor (a gap, reported): the
        // signed-in person is the one asking (or, at approval, the approver,
        // who holds at least the same authority).
        $user = auth()->user() ?? throw new LogicException('A correction needs a signed-in person.');
        $mayOverride = $user->can('sales.price.override');
        $originals = SalesInvoiceLine::query()->where('sales_invoice_id', $document->id)->whereNull('billed_delivery_for_order_id')
            ->with(['deliveryLine.orderLine', 'deliveryLine.product', 'product'])->get()->keyBy('id');
        $customer = $document->customer()->firstOrFail();
        $date = Carbon::parse($data['invoice_date'])->startOfDay();
        $lines = [];
        $capLines = [];
        $refusals = [];
        $goods = 0;

        foreach (array_values($data['lines']) as $index => $entry) {
            $original = $originals->get((int) $entry['invoice_line_id']);

            if ($original === null) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'That line is not on this invoice.']);
            }

            $quantity = (string) $entry['quantity'];

            if ((float) $quantity <= 0) {
                continue;
            }

            $deliveryLine = $original->deliveryLine;

            // What the challan line can bill once this invoice is credited.
            if ($deliveryLine !== null && (float) $quantity > (float) $original->quantity + (float) $deliveryLine->quantityUninvoiced() + 0.00005) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Only '.((float) $original->quantity + (float) $deliveryLine->quantityUninvoiced()).' of that challan line can be billed.']);
            }

            $unitPrice = (string) $entry['unit_price'];
            $discount = (string) ($entry['discount'] ?? '0');
            $name = $deliveryLine?->product?->name ?? $original->product?->name ?? 'this line';

            // ⚠ The invoice screen's yardstick (the order's price, else the list
            // price), and the issued price itself: keeping what was billed is
            // never a new concession. Anything else needs the override
            // permission and stays within the per-bill caps.
            $listPrice = $this->yardstick($deliveryLine, $customer, $quantity, $date);
            $kept = bccomp($unitPrice, (string) $original->unit_price, 4) === 0;

            if ($listPrice !== null && ! $kept && bccomp($unitPrice, $listPrice, 4) !== 0 && ! $mayOverride) {
                $refusals["lines.{$index}.unit_price"] = "Changing the price of {$name} needs the price-override permission, which your role does not have.";
            }

            $agreed = $deliveryLine?->orderLine?->discountToBill($quantity)->toTaka() ?? '0';
            $extra = bcsub($discount, $agreed, 2);

            if ($listPrice !== null && bccomp($extra, '0', 2) > 0 && bccomp($discount, (string) $original->line_discount, 2) !== 0 && ! $mayOverride) {
                $refusals["lines.{$index}.discount"] = "A discount on {$name} needs the price-override permission, which your role does not have.";
            }

            if ($listPrice !== null) {
                $capLines[] = ['quantity' => $quantity, 'unitPrice' => $unitPrice, 'discount' => bccomp($extra, '0', 2) > 0 ? $extra : '0', 'listPrice' => $listPrice];
            }

            $net = Money::ofProduct($quantity, $unitPrice)->minor - Money::ofTaka($discount)->minor;

            if ($net < 0) {
                throw ValidationException::withMessages(["lines.{$index}.discount" => 'A line discount cannot exceed the line total.']);
            }

            $goods += $net;
            $lines[] = [
                'invoice_line_id' => (int) $original->id,
                'delivery_line_id' => $deliveryLine?->id,
                'product_id' => $original->product_id,
                'description' => self::text($entry['description'] ?? null) ?? $original->description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => Money::ofTaka($discount)->toTaka(),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'The new invoice needs at least one line. To take the whole invoice back, give a credit note instead.']);
        }

        $invoiceDiscount = Money::ofTaka((string) ($data['invoice_discount'] ?? '0'));

        if ($invoiceDiscount->minor > 0 && $invoiceDiscount->minor !== Money::ofTaka((string) $document->invoice_discount)->minor && ! $mayOverride) {
            $refusals['invoice_discount'] = 'Giving a discount needs the price-override permission, which your role does not have.';
        }

        if ($refusals === [] && ($overCap = DiscountCap::refusal($user, (int) app(BusinessContext::class)->id(), $capLines, $invoiceDiscount->toTaka())) !== null) {
            $refusals['invoice_discount'] = $overCap;
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        $values = [
            'invoice_date' => $date->toDateString(),
            'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null,
            'invoice_discount' => $invoiceDiscount->toTaka(),
            'note' => self::text($data['note'] ?? null),
            'lines' => $lines,
        ];

        if ($this->sameAs($document, $values)) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        // ⚠ An estimate for the second-person basis only: the goods less the
        // typed discount, plus the delivery charge billed again. RecordSalesInvoice
        // decides the real total (an order's agreed discount) under its locks.
        $delivery = (int) SalesInvoiceLine::query()->where('sales_invoice_id', $document->id)->whereNotNull('billed_delivery_for_order_id')->sum(DB::raw('ROUND(line_net * 100)'));

        return new ReplacementData($date, max(0, $goods - $invoiceDiscount->minor + $delivery), $values, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SalesInvoice $document */
        $document->loadMissing(['customer:id,name', 'lines.deliveryLine.delivery:id,delivery_number']);
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);
        $customer = $document->customer?->name ?? 'the customer';
        $bundle = $action === DocumentAction::Reverse ? SalesInvoiceActionPolicy::voidBundle($document) : null;
        $lines = [];

        if ($bundle !== null) {
            $bundle->receipts->loadMissing('moneyAccount:id,name');

            foreach ($bundle->receipts as $receipt) {
                $lines[] = new EffectLine(Tk::of($receipt->amountMoney()->minor).' comes out of '.($receipt->moneyAccount?->name ?? 'the till')." on {$on}: hand it back to the customer.", $receipt->moneyAccount?->name, -$receipt->amountMoney()->minor, 'ledger');
            }
        }

        $lines[] = new EffectLine("A credit note takes {$document->invoice_number} back whole: ".Tk::of($total)." comes off what {$customer} owes on {$on}.", 'Accounts Receivable', -$total, 'ledger');

        $cost = $document->costReleased()->minor;

        if ($cost > 0) {
            $lines[] = new EffectLine(Tk::of($cost).' of cost goes back from Cost of Goods Sold to Goods Delivered Not Invoiced.', 'Cost of Goods Sold', -$cost, 'ledger');
        }

        $challans = $document->lines->pluck('deliveryLine.delivery.delivery_number')->filter()->unique();

        if ($bundle !== null) {
            $lines[] = new EffectLine("The goods on challan {$bundle->delivery->delivery_number} go back into stock, on the same batches.", null, null, 'stock');
        } elseif ($challans->isNotEmpty()) {
            $lines[] = new EffectLine('Challan '.$challans->implode(', ').' can be billed again.', null, null, 'allocation');
        }

        $advance = CustomerReceiptAllocation::query()->where('sales_invoice_id', $document->id)
            ->whereIn('customer_receipt_id', CustomerReceipt::query()->whereNotNull('sales_order_id')->select('id'))->sum('amount');

        if (Money::ofTaka((string) ($advance ?: '0'))->minor > 0) {
            $lines[] = new EffectLine(Tk::of(Money::ofTaka((string) $advance)->minor).' the customer paid for the order is held for it again.', null, null, 'allocation');
        }

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $lines[] = new EffectLine('The new invoice bills about '.Tk::of($replacement->totalMinor).' on '.Tk::date($replacement->date).'.', 'Accounts Receivable', $replacement->totalMinor, 'ledger');

            $paid = $this->plainPayments($document)->sum(fn ($a) => Money::ofTaka((string) $a->amount)->minor);

            if ($paid > 0) {
                $lines[] = new EffectLine('Payments of '.Tk::of($paid).' move to the new invoice.', null, $paid, 'allocation');
            }
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $document->invoice_number;

        if ($action === DocumentAction::Reverse && SalesInvoiceActionPolicy::voidBundle($document) !== null) {
            return ["{$number}, its challan and its receipt stay, marked Reversed.", 'A credit note with its own CN number cites the invoice.'];
        }

        return $action === DocumentAction::Correct
            ? ["{$number} stays exactly as issued, marked Corrected.", 'A credit note with its own CN number cites it, and the new invoice gets a new number with the label "Correction of '.$number.'".']
            : ["{$number} stays exactly as issued, marked Credited.", 'The credit note gets its own CN number and cites the invoice.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->invoice_date->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->total)->minor;
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
        /** @var SalesInvoice $document */
        $on = $ctx->dates->reversalDate;
        $reason = $ctx->reason->display();
        // Only a Reverse voids a counter sale; a Correct is never offered on one.
        $bundle = $ctx->dates->successorDate === null ? SalesInvoiceActionPolicy::voidBundle($document) : null;

        try {
            foreach ($bundle?->receipts ?? [] as $receipt) {
                $receipt = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                $reversal = $this->undoReceipt->undo($receipt, $ctx->actor, $on, 'Void sale '.$document->invoice_number.': receipt #'.$receipt->id, $ctx->pass);
                $this->undoReceipt->mark($receipt, $ctx->actor, $on, "Void sale {$document->invoice_number}: {$reason}", $reversal);
            }

            $credited = $this->credit->credit($document, $ctx->actor, $on, $reason, $ctx->pass);

            if ($bundle !== null) {
                $delivery = $bundle->delivery->newQuery()->whereKey($bundle->delivery->id)->lockForUpdate()->firstOrFail();
                $reversal = $this->undoDelivery->undo($delivery, $ctx->actor, $on, "Void sale {$document->invoice_number}: challan {$delivery->delivery_number}", $ctx->pass);
                $this->undoDelivery->mark($delivery, $ctx->actor, $on, "Void sale {$document->invoice_number}: {$reason}", $reversal);
            }
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $credited['journal'];
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var SalesInvoice $document */
        $values = $replacement->values;

        // Payments typed against the old invoice come off it before the new one
        // is raised, and go onto it after (no journal: both sit in 1140).
        $moving = $this->plainPayments($document);
        CustomerReceiptAllocation::query()->whereKey($moving->modelKeys())->delete();

        try {
            $successor = $this->record->handle(
                customer: $document->customer()->firstOrFail(),
                lines: array_map(fn (array $line) => [
                    'deliveryLine' => $line['delivery_line_id'] === null ? null : DeliveryLine::query()->find($line['delivery_line_id']),
                    'product' => $line['delivery_line_id'] === null && $line['product_id'] !== null ? Product::query()->find($line['product_id']) : null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unitPrice' => $line['unit_price'],
                    'discount' => $line['discount'],
                ], $values['lines']),
                createdBy: $ctx->actor,
                invoiceDate: $values['invoice_date'],
                invoiceDiscount: $values['invoice_discount'],
                dueDate: $values['due_date'],
                note: $values['note'],
                pass: $ctx->pass,
            );
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['invoice_date' => $e->getMessage()]);
        } catch (NoSellingPriceException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        foreach ($moving as $allocation) {
            $room = $successor->fresh()->outstanding()->minor;
            $amount = Money::ofTaka((string) $allocation->amount)->minor;

            if ($amount > $room) {
                throw ValidationException::withMessages(['lines' => "The new invoice comes to less than the payments already on {$document->invoice_number}; reverse or re-allocate part of the payment first."]);
            }

            CustomerReceiptAllocation::create([
                'business_id' => $successor->business_id,
                'customer_receipt_id' => $allocation->customer_receipt_id,
                'sales_invoice_id' => $successor->id,
                'amount' => Money::ofMinor($amount)->toTaka(),
            ]);
        }

        return $successor->fresh(['lines']);
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return SalesLedgerCheck::journalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->credit->mark($document, $ctx->actor, $ctx->dates->reversalDate, $ctx->reason->display(), $reversal);
    }

    public function successorClass(): string
    {
        return SalesInvoice::class;
    }

    /**
     * This type's own invariants: the invoice is marked, a full credit note of
     * exactly its total cites it, nothing is still allocated to it, its
     * challan lines are billed between nothing and all; the successor's
     * journal debits AR with exactly its total and it is not overpaid.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SalesInvoice $original */
        if (! $original->isReversed()) {
            throw new LogicException("Invoice {$original->invoice_number} was not marked.");
        }

        $note = SalesCreditNote::query()->where('sales_invoice_id', $original->id)->where('kind', SalesCreditNote::FULL)->get();

        if ($note->count() !== 1 || Money::ofTaka((string) $note->first()->amount)->minor !== $this->totalMinor($original)) {
            throw new LogicException("Invoice {$original->invoice_number} has no single full credit note of its total.");
        }

        if (CustomerReceiptAllocation::query()->where('sales_invoice_id', $original->id)->exists()) {
            throw new LogicException("Invoice {$original->invoice_number} still holds a payment.");
        }

        foreach (SalesInvoiceLine::query()->where('sales_invoice_id', $original->id)->whereNotNull('delivery_line_id')->with('deliveryLine')->get() as $line) {
            $billed = (float) $line->deliveryLine->quantity_invoiced;

            if ($billed < -0.00005 || $billed > (float) $line->deliveryLine->quantity + 0.00005) {
                throw new LogicException("Invoice {$original->invoice_number}: a challan line is billed outside its quantity.");
            }
        }

        if ($successor !== null) {
            /** @var SalesInvoice $successor */
            if ($successor->outstanding()->minor < 0) {
                throw new LogicException("Invoice {$successor->invoice_number} is paid past its total.");
            }

            $journal = SalesLedgerCheck::journalOf($successor);

            if ($journal !== null && SalesLedgerCheck::expectsJournal($successor)) {
                $ar = (int) $journal->lines()->where('amount_minor', '>', 0)
                    ->whereIn('account_id', DB::table('account_mappings')->where('key', 'sales.accounts_receivable')->select('account_id'))
                    ->sum('amount_minor');

                if ($ar !== $this->totalMinor($successor)) {
                    throw new LogicException("Invoice {$successor->invoice_number}'s total does not match its journal.");
                }
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    /** Receipt allocations on the invoice that are neither an order's advance nor a counter sale's own cash. */
    private function plainPayments(SalesInvoice $invoice): Collection
    {
        return CustomerReceiptAllocation::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereIn('customer_receipt_id', CustomerReceipt::query()->whereNull('sales_order_id')->select('id'))
            ->orderBy('id')
            ->get();
    }

    /** What the line would have billed untouched: the order's price, else the list price; null for a service line. */
    private function yardstick(?DeliveryLine $deliveryLine, $customer, string $quantity, Carbon $date): ?string
    {
        if ($deliveryLine === null) {
            return null;
        }

        if ($deliveryLine->orderLine !== null) {
            return (string) $deliveryLine->orderLine->unit_price;
        }

        if ($customer->isOwner() && ($cost = $deliveryLine->unitCostTaken()) !== null) {
            return $cost;
        }

        try {
            return $this->prices->resolve($deliveryLine->product, $customer, $quantity, $date)->unitPrice;
        } catch (NoSellingPriceException) {
            return null;
        }
    }

    /** @param array<string, mixed> $values */
    private function sameAs(SalesInvoice $invoice, array $values): bool
    {
        $before = [
            $invoice->invoice_date->toDateString(), $invoice->due_date?->toDateString(), Money::ofTaka((string) $invoice->invoice_discount)->minor, self::text($invoice->note),
            SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->whereNull('billed_delivery_for_order_id')->orderBy('id')->get()
                ->map(fn ($l) => [(int) $l->id, Money::ofTaka((string) $l->quantity)->minor, (string) round((float) $l->unit_price, 4), Money::ofTaka((string) $l->line_discount)->minor])->all(),
        ];

        $after = [
            $values['invoice_date'], $values['due_date'] ?? $invoice->due_date?->toDateString(), Money::ofTaka($values['invoice_discount'])->minor, $values['note'],
            collect($values['lines'])->sortBy('invoice_line_id')->map(fn ($l) => [$l['invoice_line_id'], Money::ofTaka($l['quantity'])->minor, (string) round((float) $l['unit_price'], 4), Money::ofTaka($l['discount'])->minor])->values()->all(),
        ];

        return $before === $after;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
