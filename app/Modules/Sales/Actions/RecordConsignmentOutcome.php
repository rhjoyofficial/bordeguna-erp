<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Support\DeliveryBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The courier's answer, and everything it sets in motion, in one transaction.
 *
 * - DELIVERED: the challan is invoiced (Joy, 13 September: invoiced when the
 *   courier confirms delivery), and what the courier collected is recorded as
 *   the customer's receipt into the courier's account, 1145.
 * - PARTLY DELIVERED: what the customer took is invoiced the same way; the rest
 *   comes back as goods never invoiced.
 * - RETURNED: no invoice at all; the goods come back.
 *
 * ⚠⚠ THE COLLECTED AMOUNT MUST EQUAL THE INVOICE. A courier that collected a
 * different figure with nothing coming back means somebody agreed a different
 * price at the door, and that is a decision to record, not a rounding to absorb.
 * Refused, with both figures named, and nothing written.
 *
 * ⚠ The existing actions do the work - RecordSalesReturn, RecordSalesInvoice and
 * RecordCustomerReceipt - so a parcel's documents are the same documents a
 * counter produces, with the same guards and the same journals.
 */
class RecordConsignmentOutcome
{
    public function __construct(
        private readonly RecordSalesInvoice $invoices,
        private readonly RecordCustomerReceipt $receipts,
        private readonly RecordSalesReturn $returns,
        private readonly ResolveSellingPrice $resolver,
    ) {}

    /**
     * @param  array<int, array{taken?: string, written_off?: string}>  $lines  keyed by delivery line id;
     *                                                                          ignored for a full delivery
     *
     * @throws ValidationException
     */
    public function handle(
        Consignment $consignment,
        ConsignmentStatus $outcome,
        mixed $on,
        string $collectedAmount,
        array $lines,
        User $recordedBy,
        ?string $note = null,
    ): Consignment {
        if ($outcome === ConsignmentStatus::Booked) {
            throw ValidationException::withMessages(['outcome' => 'Choose what the courier reported.']);
        }

        $date = Carbon::parse($on ?? 'today')->startOfDay();
        $collected = Money::ofTaka($collectedAmount);

        if ($collected->minor < 0) {
            throw ValidationException::withMessages(['collected_amount' => 'The amount collected cannot be negative.']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['outcome_on' => 'A courier cannot have reported on a day that has not happened yet.']);
        }

        // ⚠ A refused parcel whose customer still paid something (a courier's
        // "paid return") is money for nothing billed. Not handled yet, so refused
        // rather than guessed at.
        if ($outcome === ConsignmentStatus::Returned && $collected->minor > 0) {
            throw ValidationException::withMessages([
                'collected_amount' => 'A returned parcel with money collected is not handled yet. Record 0, and tell whoever keeps the books about the money.',
            ]);
        }

        return DB::transaction(function () use ($consignment, $outcome, $date, $collected, $lines, $recordedBy, $note) {
            /** @var Consignment $consignment */
            $consignment = Consignment::query()->whereKey($consignment->id)->lockForUpdate()->with('courier', 'delivery.lines', 'delivery.customer')->firstOrFail();

            if (! $consignment->isBooked()) {
                throw ValidationException::withMessages([
                    'outcome' => "Consignment {$consignment->consignment_number} is already recorded as {$consignment->status->label()}.",
                ]);
            }

            $delivery = $consignment->delivery;

            if ($date->lt($delivery->delivered_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'outcome_on' => "The parcel left on {$delivery->delivered_at->toDateString()}; it cannot have arrived before that.",
                ]);
            }

            [$taken, $toStock, $writtenOff] = $this->split($outcome, $delivery->lines, $lines);

            // ⚠ The outcome is written FIRST, so the invoice and the return below
            // see a challan that is no longer waiting on the courier.
            $consignment->forceFill([
                'status' => $outcome,
                'outcome_on' => $date,
                'collected_amount' => $collected->toTaka(),
                'outcome_note' => $note,
                'outcome_recorded_by' => $recordedBy->id,
            ])->save();

            $memo = "{$consignment->courier->name} consignment {$consignment->consignment_number}";

            if (array_sum(array_map('floatval', $toStock)) + array_sum(array_map('floatval', $writtenOff)) > 0) {
                $return = $this->returns->handle(
                    delivery: $delivery,
                    lines: $delivery->lines
                        ->filter(fn (DeliveryLine $line) => (float) $toStock[$line->id] > 0 || (float) $writtenOff[$line->id] > 0)
                        ->map(fn (DeliveryLine $line) => [
                            'deliveryLine' => $line,
                            'quantityToStock' => $toStock[$line->id],
                            'quantityWrittenOff' => $writtenOff[$line->id],
                        ])
                        ->values()
                        ->all(),
                    createdBy: $recordedBy,
                    returnedAt: $date,
                    note: "Returned by {$memo}".($note ? ": {$note}" : ''),
                );

                $consignment->forceFill(['sales_return_id' => $return->id])->save();
            }

            if ($outcome === ConsignmentStatus::Returned) {
                return $consignment->fresh(['courier', 'invoice', 'receipt', 'salesReturn']);
            }

            $invoice = $this->invoices->handle(
                customer: $delivery->customer,
                lines: DeliveryBilling::lines($delivery->fresh(['lines.product', 'lines.orderLine', 'customer']), $taken, $this->resolver, $date),
                createdBy: $recordedBy,
                invoiceDate: $date,
                note: "Delivered by {$memo}",
            );

            $consignment->forceFill(['sales_invoice_id' => $invoice->id])->save();

            $total = Money::ofTaka((string) $invoice->total);

            // ⭐ What the customer still owed at the door: the invoice, less
            // money already paid for the order (SSLCommerz slice P1), which the
            // invoice took as it was raised. A parcel booked as already paid for
            // collects nothing, and anything its invoice still owes waits to be
            // receipted in the usual way.
            $due = $invoice->outstanding();
            $paidAhead = Money::ofMinor($total->minor - $due->minor);
            $expected = $consignment->codAmount()->isZero() ? Money::zero() : $due;

            if ($collected->minor !== $expected->minor) {
                throw ValidationException::withMessages([
                    'collected_amount' => match (true) {
                        $consignment->codAmount()->isZero() => "This parcel was booked as already paid for, so the courier should have collected nothing, not {$collected->format()}.",
                        $paidAhead->minor > 0 => "The invoice for what the customer took comes to {$total->format()} and {$paidAhead->format()} of it was paid in advance, so the courier should have collected {$due->format()}, not {$collected->format()}.",
                        default => "The invoice for what the customer took comes to {$total->format()}, but the courier collected {$collected->format()}. "
                            .'If part of the parcel came back, record a partial delivery; a different price agreed at the door is not handled yet.',
                    },
                ]);
            }

            if ($collected->minor > 0) {
                $receipt = $this->receipts->handle(
                    customer: $delivery->customer,
                    moneyAccount: $consignment->courier,
                    receivedOn: $date,
                    allocations: [['invoice' => $invoice, 'amount' => $collected->toTaka()]],
                    createdBy: $recordedBy,
                    reference: $consignment->consignment_number,
                    note: "Cash on delivery collected by {$memo}",
                    consignment: $consignment,
                );

                $consignment->forceFill(['customer_receipt_id' => $receipt->id])->save();
            }

            return $consignment->fresh(['courier', 'invoice', 'receipt', 'salesReturn']);
        });
    }

    /**
     * How much of each line the customer took, how much came back to stock and
     * how much came back unsellable.
     *
     * @param  iterable<DeliveryLine>  $deliveryLines
     * @param  array<int, array{taken?: string, written_off?: string}>  $input
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    private function split(ConsignmentStatus $outcome, iterable $deliveryLines, array $input): array
    {
        $taken = [];
        $toStock = [];
        $writtenOff = [];
        $anyTaken = false;
        $anyBack = false;

        foreach ($deliveryLines as $line) {
            $quantity = $this->scale($line->quantityUninvoiced());
            $entry = $input[$line->id] ?? [];

            $take = match ($outcome) {
                ConsignmentStatus::Delivered => $quantity,
                ConsignmentStatus::Returned => 0,
                default => $this->scale($entry['taken'] ?? '0'),
            };

            $back = $quantity - $take;
            $off = $outcome === ConsignmentStatus::Delivered ? 0 : $this->scale($entry['written_off'] ?? '0');

            if ($take < 0 || $take > $quantity) {
                throw ValidationException::withMessages([
                    "lines.{$line->id}.taken" => sprintf('Between 0 and %s of this line can have been taken.', $line->quantityUninvoiced()),
                ]);
            }

            if ($off < 0 || $off > $back) {
                throw ValidationException::withMessages([
                    "lines.{$line->id}.written_off" => sprintf('Only the %s that came back can be written off.', $this->unscale($back)),
                ]);
            }

            $taken[$line->id] = $this->unscale($take);
            $toStock[$line->id] = $this->unscale($back - $off);
            $writtenOff[$line->id] = $this->unscale($off);
            $anyTaken = $anyTaken || $take > 0;
            $anyBack = $anyBack || $back > 0;
        }

        if ($outcome === ConsignmentStatus::PartiallyDelivered && (! $anyTaken || ! $anyBack)) {
            throw ValidationException::withMessages([
                'lines' => 'A partial delivery means the customer took some of the parcel and some came back. Record a full delivery or a return instead.',
            ]);
        }

        return [$taken, $toStock, $writtenOff];
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
