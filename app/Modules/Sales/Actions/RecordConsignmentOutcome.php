<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\DoorResolution;
use App\Modules\Sales\Exceptions\DoorDiscountNeedsApproval;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\DiscountCap;
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
 * - PAID RETURN (slice 8, ruling 2): the goods come back exactly as a return,
 *   and what the customer paid the courier is delivery income: Dr 1145 naming
 *   the courier / Cr 4300. No goods revenue is ever recognised.
 *
 * ⭐⭐ WHAT WAS COLLECTED MAY DIFFER FROM WHAT WAS OWED (slice 8, ChatGPT ruling
 * 3: not every difference is a discount). A difference is refused unless the
 * person says how it is settled, with a reason:
 * - DISCOUNT at the door: the invoice stays at the agreed price, the receipt is
 *   what was collected, and the difference is a discount allowed against that
 *   invoice (party adjustment, Dr 4210 / Cr 1140). Within the confirming
 *   person's DiscountCap; above it, or without sales.price.override, nothing is
 *   written and the caller raises an Owner approval request.
 * - PRICE CORRECTION: the invoice lines carry the corrected prices with the
 *   counter's price trail (list price, reason, who). Needs sales.price.override.
 * - SHORT COLLECTION: invoice at full price, receipt for what was collected,
 *   the rest stays on the customer's account.
 * - ROUNDING, under Tk 1: paisa short are written off to the rounding head;
 *   paisa over (cash on delivery rounded up) are Dr 1145 / Cr rounding.
 *
 * ⭐ CUT-OFF (risk 9): a courier's date inside a closed month is kept as the
 * courier's date, and every document is posted on the first open day, with a
 * note saying so. Nothing is written into the locked month.
 *
 * ⚠ The existing actions do the work - RecordSalesReturn, RecordSalesInvoice,
 * RecordCustomerReceipt, RecordPartyAdjustment, PostJournal - so a parcel's
 * documents are the same documents a counter produces, with the same guards
 * and the same journals.
 */
class RecordConsignmentOutcome
{
    /** Below this many paisa a difference may be settled as rounding. */
    public const ROUNDING_LIMIT_MINOR = 100;

    public function __construct(
        private readonly RecordSalesInvoice $invoices,
        private readonly RecordCustomerReceipt $receipts,
        private readonly RecordSalesReturn $returns,
        private readonly ResolveSellingPrice $resolver,
        private readonly RecordPartyAdjustment $adjustments,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * @param  array<int, array{taken?: string, written_off?: string}>  $lines  keyed by delivery line id;
     *                                                                          ignored for a full delivery
     * @param  array<int, string>  $prices  corrected unit prices keyed by delivery line id (price correction only)
     *
     * @throws ValidationException
     * @throws DoorDiscountNeedsApproval
     */
    public function handle(
        Consignment $consignment,
        ConsignmentStatus $outcome,
        mixed $on,
        string $collectedAmount,
        array $lines,
        User $recordedBy,
        ?string $note = null,
        ?DoorResolution $resolution = null,
        ?string $reason = null,
        array $prices = [],
        // The Owner who approved a door discount above the cap (slice 8).
        ?User $discountApprovedBy = null,
    ): Consignment {
        if (! in_array($outcome, ConsignmentStatus::outcomes(), true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose what the courier reported.']);
        }

        $date = Carbon::parse($on ?? 'today')->startOfDay();
        $collected = Money::ofTaka($collectedAmount);
        $reason = trim((string) $reason) ?: null;

        if ($collected->minor < 0) {
            throw ValidationException::withMessages(['collected_amount' => 'The amount collected cannot be negative.']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['outcome_on' => 'A courier cannot have reported on a day that has not happened yet.']);
        }

        // ⚠ A refused parcel whose customer still paid something is a PAID
        // RETURN, recorded as one so the money lands as delivery income.
        if ($outcome === ConsignmentStatus::Returned && $collected->minor > 0) {
            throw ValidationException::withMessages([
                'collected_amount' => 'A returned parcel with money collected is a paid return. Choose "Paid return" so the money is recorded as delivery income.',
            ]);
        }

        if ($outcome === ConsignmentStatus::PaidReturn && $collected->isZero()) {
            throw ValidationException::withMessages([
                'collected_amount' => 'A paid return collected something. With nothing collected, record it as returned.',
            ]);
        }

        if ($resolution !== null && $outcome->bringsEverythingBack()) {
            $resolution = null;
        }

        if ($resolution !== null && ($reason === null || mb_strlen($reason) < 3)) {
            throw ValidationException::withMessages(['door_reason' => 'Say why the amount differs: the reason is part of the record.']);
        }

        if ($resolution === DoorResolution::PriceCorrection && ! $recordedBy->can('sales.price.override')) {
            throw ValidationException::withMessages([
                'resolution' => 'Correcting a price needs the right to change prices. Ask a manager or the Owner to record this parcel.',
            ]);
        }

        // ⭐ Risk 9: the courier's day, or the first day the books are open.
        $postOn = AccountingPeriod::firstOpenOnOrAfter($date);
        $cutoffNote = $postOn->equalTo($date) ? null
            : "Reported by the courier on {$date->toDateString()}, in a closed period; posted on {$postOn->toDateString()}.";

        return DB::transaction(function () use ($consignment, $outcome, $date, $postOn, $cutoffNote, $collected, $lines, $recordedBy, $note, $resolution, $reason, $prices, $discountApprovedBy) {
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

            [$taken, $toStock, $writtenOff] = self::split($outcome, $delivery->lines, $lines);

            // ⚠ The outcome is written FIRST, so the invoice and the return below
            // see a challan that is no longer waiting on the courier.
            $consignment->forceFill([
                'status' => $outcome,
                'outcome_on' => $date,
                'posted_on' => $postOn,
                'cutoff_note' => $cutoffNote,
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
                    returnedAt: $postOn,
                    note: "Returned by {$memo}".($note ? ": {$note}" : ''),
                );

                $consignment->forceFill(['sales_return_id' => $return->id])->save();
            }

            if ($outcome === ConsignmentStatus::PaidReturn) {
                $this->postPaidReturn($consignment, $collected, $postOn, $memo, $recordedBy);
            }

            if ($outcome->bringsEverythingBack()) {
                return $consignment->fresh(['courier', 'invoice', 'receipt', 'salesReturn']);
            }

            $billing = DeliveryBilling::lines($delivery->fresh(['lines.product', 'lines.orderLine', 'customer']), $taken, $this->resolver, $postOn);

            if ($resolution === DoorResolution::PriceCorrection) {
                $billing = $this->corrected($billing, $prices, (string) $reason, $recordedBy);
                $this->assertCorrectionWithinCap($billing, $consignment->business_id, $recordedBy, $discountApprovedBy, $collected);
            }

            $invoice = $this->invoices->handle(
                customer: $delivery->customer,
                lines: $billing,
                createdBy: $recordedBy,
                invoiceDate: $postOn,
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

            if ($consignment->codAmount()->isZero() && ! $collected->isZero()) {
                throw ValidationException::withMessages([
                    'collected_amount' => "This parcel was booked as already paid for, so the courier should have collected nothing, not {$collected->format()}.",
                ]);
            }

            $expected = $consignment->codAmount()->isZero() ? Money::zero() : $due;
            $difference = Money::ofMinor($expected->minor - $collected->minor);

            if (! $difference->isZero()) {
                $this->assertResolvable($resolution, $difference, $expected, $collected, $total, $paidAhead, $billing, $consignment->business_id, $recordedBy, $discountApprovedBy, (string) $reason);
            }

            // What the receipt into the courier carries: never more than the
            // invoice still owes. Paisa over it are rounding, posted below.
            $receiptAmount = Money::ofMinor(min($collected->minor, $expected->minor));

            if ($receiptAmount->minor > 0) {
                $receipt = $this->receipts->handle(
                    customer: $delivery->customer,
                    moneyAccount: $consignment->courier,
                    receivedOn: $postOn,
                    allocations: [['invoice' => $invoice, 'amount' => $receiptAmount->toTaka()]],
                    createdBy: $recordedBy,
                    reference: $consignment->consignment_number,
                    note: "Cash on delivery collected by {$memo}",
                    consignment: $consignment,
                );

                $consignment->forceFill(['customer_receipt_id' => $receipt->id])->save();
            }

            if ($resolution !== null && ($resolution === DoorResolution::PriceCorrection || ! $difference->isZero())) {
                $adjustmentId = $this->settleDifference($resolution, $difference, $invoice, $consignment, $postOn, $memo, (string) $reason, $discountApprovedBy ?? $recordedBy);

                $consignment->forceFill([
                    'door_resolution' => $resolution,
                    'door_difference' => $difference->toTaka(),
                    'door_reason' => $reason,
                    'party_adjustment_id' => $adjustmentId,
                ])->save();

                activity('courier.door')
                    ->performedOn($consignment)
                    ->causedBy($recordedBy)
                    ->withProperties([
                        'resolution' => $resolution->value,
                        'owed' => $expected->toTaka(),
                        'collected' => $collected->toTaka(),
                        'difference' => $difference->toTaka(),
                        'reason' => $reason,
                        'approved_by' => $discountApprovedBy?->id,
                    ])
                    ->log("{$resolution->label()} on consignment {$consignment->consignment_number}");
            }

            return $consignment->fresh(['courier', 'invoice', 'receipt', 'salesReturn']);
        });
    }

    /**
     * Refuses a difference the chosen resolution cannot explain, naming both
     * figures. A discount the person may not give alone is handed back to the
     * caller as an approval request, with nothing written.
     *
     * @param  array<int, array<string, mixed>>  $billing
     */
    private function assertResolvable(
        ?DoorResolution $resolution,
        Money $difference,
        Money $expected,
        Money $collected,
        Money $total,
        Money $paidAhead,
        array $billing,
        int $businessId,
        User $recordedBy,
        ?User $approvedBy,
        string $reason,
    ): void {
        $figures = $paidAhead->minor > 0
            ? "The invoice for what the customer took comes to {$total->format()} and {$paidAhead->format()} of it was paid in advance, so the courier should have collected {$expected->format()}, not {$collected->format()}."
            : "The invoice for what the customer took comes to {$total->format()}, but the courier collected {$collected->format()}.";

        $refuse = fn (string $why) => throw ValidationException::withMessages(['collected_amount' => "{$figures} {$why}"]);

        match ($resolution) {
            null => $refuse('If part of the parcel came back, record a partial delivery; otherwise choose how the difference is settled and say why.'),
            DoorResolution::PriceCorrection => $refuse('With the corrected prices the amounts still differ: correct the prices so the invoice matches what was collected, or choose another way to settle it.'),
            DoorResolution::Rounding => abs($difference->minor) < self::ROUNDING_LIMIT_MINOR ? null
                : $refuse('Rounding settles a difference under Tk 1 only.'),
            DoorResolution::ShortCollection => $difference->minor > 0 ? null
                : $refuse('More was collected than was owed, so nothing is short.'),
            DoorResolution::Discount => $difference->minor > 0 ? null
                : $refuse('More was collected than was owed, so it was not a discount.'),
        };

        if ($resolution !== DoorResolution::Discount) {
            return;
        }

        if ($approvedBy !== null) {
            if (! $approvedBy->hasRole('Owner') && ! $approvedBy->is_super_admin) {
                throw ValidationException::withMessages(['resolution' => 'Only the Owner approves a discount above the cap.']);
            }

            return;
        }

        $why = ! $recordedBy->can('sales.price.override')
            ? 'Giving a discount needs the right to change prices.'
            : DiscountCap::refusal(
                $recordedBy,
                $businessId,
                array_map(fn (array $line) => [
                    'quantity' => (string) $line['quantity'],
                    'unitPrice' => (string) $line['unitPrice'],
                    'discount' => '0',
                    'listPrice' => (string) $line['unitPrice'],
                ], $billing),
                $difference->toTaka(),
            );

        if ($why !== null) {
            throw new DoorDiscountNeedsApproval($expected, $collected, $difference, $why);
        }
    }

    /**
     * The journal or document that settles the difference. Returns the party
     * adjustment it wrote, if any.
     */
    private function settleDifference(DoorResolution $resolution, Money $difference, SalesInvoice $invoice, Consignment $consignment, Carbon $on, string $memo, string $reason, User $by): ?int
    {
        $adjust = fn (string $kind, string $label) => $this->adjustments->handle(
            kind: $kind,
            date: $on,
            customer: $invoice->customer,
            supplier: null,
            invoices: [$invoice->id => $difference->toTaka()],
            bills: [],
            reason: "{$label}, {$memo}: {$reason}",
            by: $by,
        )->id;

        return match (true) {
            $resolution === DoorResolution::Discount => $adjust('customer_discount', 'Discount at the door'),
            $resolution === DoorResolution::Rounding && $difference->minor > 0 => $adjust('rounding', 'Rounding at the door'),
            $resolution === DoorResolution::Rounding && $difference->minor < 0 => $this->postRoundingGain($consignment, $difference->negated(), $on, $memo, $reason, $by),
            default => null,
        };
    }

    /**
     * Paisa collected over the invoice (cash on delivery rounded up): the
     * courier holds them, and they are a rounding gain. Never a receipt, which
     * would leave the customer in credit by a few paisa forever.
     */
    private function postRoundingGain(Consignment $consignment, Money $over, Carbon $on, string $memo, string $reason, User $by): ?int
    {
        $courier = $consignment->courier;
        $gain = AccountMapping::forKeyOn('accounting.rounding_difference', $on)?->account_id
            ?? AccountMapping::resolve('sales.discounts_allowed', $on)->id;

        $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit((int) Account::findOrFail($courier->account_id)->id, $over, "Rounded up at the door, {$memo}", null, $courier),
                JournalLineDraft::credit((int) $gain, $over, "Rounding at the door, {$memo}: {$reason}"),
            ],
            memo: "Rounding at the door, {$memo}",
            source: $consignment,
        ), $by);

        return null;
    }

    /**
     * ⭐ Ruling 2: what a customer paid for a parcel they refused is delivery
     * income the courier now holds. Dr 1145 naming the courier / Cr 4300.
     */
    private function postPaidReturn(Consignment $consignment, Money $collected, Carbon $on, string $memo, User $by): void
    {
        $courier = $consignment->courier;

        $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit((int) Account::findOrFail($courier->account_id)->id, $collected, "Delivery charge collected on a refused parcel, {$memo}", null, $courier),
                JournalLineDraft::credit(AccountMapping::resolve('sales.delivery_income', $on)->id, $collected, "Paid return, {$memo}"),
            ],
            memo: "Paid return, {$memo}",
            source: $consignment,
        ), $by);
    }

    /**
     * The invoice lines at the corrected prices, each carrying what the agreed
     * price was, why it changed and who changed it (the counter's price trail).
     *
     * @param  array<int, array<string, mixed>>  $billing
     * @param  array<int, string>  $prices
     * @return array<int, array<string, mixed>>
     */
    /**
     * Joy, 11 Oct 2026: a price corrected at the door is a concession like a
     * discount, so it answers to the same per-bill cap (DiscountCap: Owner none,
     * Manager or person in charge 3%, other staff 1% or Tk 300). Above the cap
     * nothing is posted and the whole answer waits for the Owner, exactly like a
     * door discount; the Owner's approval replays these prices.
     *
     * @param  list<array<string, mixed>>  $billing
     *
     * @throws DoorDiscountNeedsApproval
     */
    private function assertCorrectionWithinCap(array $billing, int $businessId, User $recordedBy, ?User $approvedBy, Money $collected): void
    {
        if ($approvedBy !== null && ($approvedBy->hasRole('Owner') || $approvedBy->is_super_admin)) {
            return;
        }

        $lines = array_map(fn (array $line) => [
            'quantity' => (string) $line['quantity'],
            'unitPrice' => (string) $line['unitPrice'],
            'discount' => '0',
            'listPrice' => (string) ($line['listPrice'] ?? $line['unitPrice']),
        ], $billing);

        $why = DiscountCap::refusal($recordedBy, $businessId, $lines, '0');

        if ($why === null) {
            return;
        }

        $cut = 0;
        $due = 0;
        foreach ($lines as $line) {
            $cut += max(0, Money::ofProduct($line['quantity'], bcsub($line['listPrice'], $line['unitPrice'], 4))->minor);
            $due += Money::ofProduct($line['quantity'], $line['listPrice'])->minor;
        }

        throw new DoorDiscountNeedsApproval(Money::ofMinor($due), $collected, Money::ofMinor($cut), 'A price correction is a concession too. '.$why);
    }

    private function corrected(array $billing, array $prices, string $reason, User $by): array
    {
        $changed = false;

        foreach ($billing as $index => $line) {
            $id = $line['deliveryLine']->id;

            if (! array_key_exists($id, $prices) || trim((string) $prices[$id]) === '') {
                continue;
            }

            $price = Money::ofTaka((string) $prices[$id]);

            if ($price->minor < 0) {
                throw ValidationException::withMessages(["prices.{$id}" => 'A price cannot be negative.']);
            }

            $agreed = (string) $line['unitPrice'];

            if (bccomp($price->toTaka(), $agreed, 4) === 0) {
                continue;
            }

            $billing[$index]['listPrice'] = $agreed;
            $billing[$index]['unitPrice'] = $price->toTaka();
            $billing[$index]['overrideReason'] = $reason;
            $billing[$index]['overriddenBy'] = $by;
            $changed = true;
        }

        if (! $changed) {
            throw ValidationException::withMessages(['prices' => 'Type the corrected price of at least one line.']);
        }

        return $billing;
    }

    /**
     * How much of each line the customer took, how much came back to stock and
     * how much came back unsellable.
     *
     * @param  iterable<DeliveryLine>  $deliveryLines
     * @param  array<int, array{taken?: string, written_off?: string}>  $input
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>}
     */
    public static function split(ConsignmentStatus $outcome, iterable $deliveryLines, array $input): array
    {
        $taken = [];
        $toStock = [];
        $writtenOff = [];
        $anyTaken = false;
        $anyBack = false;

        foreach ($deliveryLines as $line) {
            $quantity = self::scale($line->quantityUninvoiced());
            $entry = $input[$line->id] ?? [];

            $take = match (true) {
                $outcome === ConsignmentStatus::Delivered => $quantity,
                $outcome->bringsEverythingBack() => 0,
                default => self::scale($entry['taken'] ?? '0'),
            };

            $back = $quantity - $take;
            $off = $outcome === ConsignmentStatus::Delivered ? 0 : self::scale($entry['written_off'] ?? '0');

            if ($take < 0 || $take > $quantity) {
                throw ValidationException::withMessages([
                    "lines.{$line->id}.taken" => sprintf('Between 0 and %s of this line can have been taken.', $line->quantityUninvoiced()),
                ]);
            }

            if ($off < 0 || $off > $back) {
                throw ValidationException::withMessages([
                    "lines.{$line->id}.written_off" => sprintf('Only the %s that came back can be written off.', self::unscale($back)),
                ]);
            }

            $taken[$line->id] = self::unscale($take);
            $toStock[$line->id] = self::unscale($back - $off);
            $writtenOff[$line->id] = self::unscale($off);
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

    private static function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private static function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
