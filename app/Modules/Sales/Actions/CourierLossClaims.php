<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierLossClaim;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Models\DeliveryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A parcel the courier lost or damaged, from the report to the money (courier
 * hub slice 8, ChatGPT ruling 5, Joy's approved 4960).
 *
 * REPORT: the parcel closes as lost or damaged. Its goods come back on paper
 * through a sales return to stock, at the very layer cost the dispatch took
 * (Dr 1150 / Cr 1155), and leave again through the ordinary inventory
 * adjustment flow, type lost_or_stolen or damaged, sourced on the claim: its
 * approval workflow, its FIFO cost, its journal (Dr loss / Cr 1150). Nothing is
 * invoiced, so no revenue is recognised for goods nobody received.
 *
 * COMPENSATION: on a payout statement (PostCourierStatement credits 4960 naming
 * the courier and links the line here by the parcel), or paid straight into a
 * bank: Dr bank / Cr 4960 with the courier's money account on the 4960 line.
 * Either closes the claim as paid.
 *
 * ⚠ The return to stock and the write-off are two documents on purpose: the
 * write-off may wait for its approver, and until then the stock list shows the
 * units with an adjustment pending against them, which is the truth of what
 * the books know.
 */
class CourierLossClaims
{
    public function __construct(
        private readonly RecordSalesReturn $returns,
        private readonly CreateInventoryAdjustment $adjustments,
        private readonly PostJournal $postJournal,
    ) {}

    public function report(Consignment $consignment, string $kind, string $claimedAmount, mixed $on, User $by, ?string $notes = null): CourierLossClaim
    {
        if (! array_key_exists($kind, CourierLossClaim::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Say whether the parcel was lost or damaged.']);
        }

        $claimed = Money::ofTaka($claimedAmount);

        if ($claimed->minor < 0) {
            throw ValidationException::withMessages(['claimed_amount' => 'The amount claimed cannot be negative.']);
        }

        $date = Carbon::parse($on ?? 'today')->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['reported_on' => 'The courier cannot have reported it on a day that has not happened yet.']);
        }

        $postOn = AccountingPeriod::firstOpenOnOrAfter($date);
        $cutoffNote = $postOn->equalTo($date) ? null
            : "Reported on {$date->toDateString()}, in a closed period; posted on {$postOn->toDateString()}.";

        return DB::transaction(function () use ($consignment, $kind, $claimed, $date, $postOn, $cutoffNote, $by, $notes) {
            /** @var Consignment $consignment */
            $consignment = Consignment::query()->whereKey($consignment->id)->lockForUpdate()->with('courier', 'delivery.lines')->firstOrFail();

            if (! $consignment->isBooked()) {
                throw ValidationException::withMessages([
                    'kind' => "Consignment {$consignment->consignment_number} is already recorded as {$consignment->status->label()}.",
                ]);
            }

            $delivery = $consignment->delivery;

            if ($date->lt($delivery->delivered_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'reported_on' => "The parcel left on {$delivery->delivered_at->toDateString()}; it cannot have been lost before that.",
                ]);
            }

            $consignment->forceFill([
                'status' => $kind === CourierLossClaim::LOST ? ConsignmentStatus::Lost : ConsignmentStatus::Damaged,
                'outcome_on' => $date,
                'posted_on' => $postOn,
                'cutoff_note' => $cutoffNote,
                'collected_amount' => '0',
                'outcome_note' => $notes,
                'outcome_recorded_by' => $by->id,
            ])->save();

            $memo = "{$consignment->courier->name} consignment {$consignment->consignment_number}";

            $return = $this->returns->handle(
                delivery: $delivery,
                lines: $delivery->lines
                    ->filter(fn (DeliveryLine $line) => (float) $line->quantityUninvoiced() > 0)
                    ->map(fn (DeliveryLine $line) => [
                        'deliveryLine' => $line,
                        'quantityToStock' => $line->quantityUninvoiced(),
                        'quantityWrittenOff' => '0',
                    ])
                    ->values()
                    ->all(),
                createdBy: $by,
                returnedAt: $postOn,
                note: CourierLossClaim::KINDS[$kind]." by {$memo}, back on paper to be written off",
            );

            $return->load('lines.movementLinks');

            $claim = CourierLossClaim::create([
                'business_id' => $consignment->business_id,
                'consignment_id' => $consignment->id,
                'money_account_id' => $consignment->money_account_id,
                'kind' => $kind,
                'reported_on' => $date,
                'value_at_cost' => Money::sum($return->lines->map(fn ($line) => Money::ofTaka((string) $line->cost_restored)))->toTaka(),
                'claimed_amount' => $claimed->toTaka(),
                'status' => CourierLossClaim::OPEN,
                'notes' => $notes,
                'sales_return_id' => $return->id,
                'reported_by' => $by->id,
            ]);

            $consignment->forceFill(['sales_return_id' => $return->id])->save();

            // One write-off per product and warehouse the goods came back to.
            $restored = StockMovement::query()
                ->whereIn('id', $return->lines->flatMap(fn ($line) => $line->movementLinks->pluck('restored_movement_id'))->filter())
                ->with('product', 'warehouse')
                ->get()
                ->groupBy(fn (StockMovement $movement) => $movement->product_id.'-'.$movement->warehouse_id);

            $reason = Reason::firstOrCreate(
                ['business_id' => $consignment->business_id, 'context' => 'inventory.stock_adjustment', 'name' => $kind === CourierLossClaim::LOST ? 'Lost by courier' : 'Damaged by courier'],
                ['is_active' => true],
            );

            foreach ($restored as $movements) {
                $first = $movements->first();

                $this->adjustments->handle(
                    type: $kind === CourierLossClaim::LOST ? InventoryAdjustmentType::LostOrStolen : InventoryAdjustmentType::Damaged,
                    direction: AdjustmentDirection::Decrease,
                    product: $first->product,
                    warehouse: $first->warehouse,
                    quantity: number_format($movements->sum(fn (StockMovement $m) => (float) $m->quantity), 4, '.', ''),
                    reason: $reason,
                    createdBy: $by,
                    note: CourierLossClaim::KINDS[$kind]." by {$memo}",
                    effectiveDate: $postOn,
                    source: $claim,
                );
            }

            activity('courier.claim')
                ->performedOn($claim)
                ->causedBy($by)
                ->withProperties(['consignment' => $consignment->consignment_number, 'claimed' => $claimed->toTaka()])
                ->log(CourierLossClaim::KINDS[$kind]." parcel reported to {$consignment->courier->name}");

            return $claim->fresh();
        });
    }

    /** The courier agreed to pay; the amount may change to what it accepted. */
    public function accept(CourierLossClaim $claim, User $by, ?string $acceptedAmount = null, ?string $note = null): CourierLossClaim
    {
        $this->assertOpen($claim);

        if ($claim->status !== CourierLossClaim::OPEN) {
            throw ValidationException::withMessages(['claim' => 'Only an open claim can be accepted.']);
        }

        $claim->forceFill([
            'status' => CourierLossClaim::ACCEPTED,
            'claimed_amount' => $acceptedAmount === null || trim($acceptedAmount) === '' ? $claim->claimed_amount : Money::ofTaka($acceptedAmount)->toTaka(),
            'notes' => $this->withNote($claim, $note),
        ])->save();

        return $claim;
    }

    public function reject(CourierLossClaim $claim, User $by, string $note): CourierLossClaim
    {
        $this->assertOpen($claim);

        if (mb_strlen(trim($note)) < 3) {
            throw ValidationException::withMessages(['note' => 'Say why the courier refused the claim.']);
        }

        $claim->forceFill([
            'status' => CourierLossClaim::REJECTED,
            'notes' => $this->withNote($claim, $note),
            'closed_by' => $by->id,
            'closed_at' => now(),
        ])->save();

        return $claim;
    }

    /**
     * Compensation paid outside a statement: Dr the bank it landed in / Cr 4960
     * naming the courier. Closes the claim.
     */
    public function compensationReceived(CourierLossClaim $claim, MoneyAccount $into, string $amount, mixed $on, User $by, ?string $reference = null): CourierLossClaim
    {
        $this->assertOpen($claim);

        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount the courier paid.']);
        }

        if ($into->isCourier() || $into->isGateway() || ! $into->is_active || $into->business_id !== $claim->business_id || $into->account_id === null) {
            throw ValidationException::withMessages(['money_account_id' => 'Choose the bank, cash or wallet account the money arrived in.']);
        }

        $date = Carbon::parse($on ?? 'today')->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['received_on' => 'The money cannot have arrived on a day that has not happened yet.']);
        }

        $postOn = AccountingPeriod::firstOpenOnOrAfter($date);

        return DB::transaction(function () use ($claim, $into, $money, $date, $postOn, $by, $reference) {
            $claim = CourierLossClaim::query()->whereKey($claim->id)->lockForUpdate()->with('courier', 'consignment')->firstOrFail();
            $this->assertOpen($claim);

            $courier = $claim->courier;
            $memo = "Compensation from {$courier->name}, consignment {$claim->consignment->consignment_number}";

            $this->postJournal->handle(new JournalDraft(
                date: $postOn,
                lines: [
                    JournalLineDraft::debit((int) Account::findOrFail($into->account_id)->id, $money, $memo, null, $into),
                    JournalLineDraft::credit(AccountMapping::resolve('courier.compensation', $postOn)->id, $money, $memo, null, $courier),
                ],
                memo: $memo.($postOn->equalTo($date) ? '' : " (received {$date->toDateString()}, in a closed period)"),
                source: $claim,
            ), $by);

            $claim->forceFill([
                'compensation_received' => Money::ofMinor(Money::ofTaka((string) $claim->compensation_received)->minor + $money->minor)->toTaka(),
                'compensation_money_account_id' => $into->id,
                'compensation_on' => $date,
                'compensation_reference' => trim((string) $reference) ?: null,
                'status' => CourierLossClaim::PAID,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            return $claim;
        });
    }

    /**
     * A posted statement paid compensation for a parcel: the claim on that
     * parcel takes the line and closes. Called inside the posting transaction.
     */
    public static function settleFromStatement(CourierStatementLine $line, ?Consignment $consignment, User $by): void
    {
        if ($consignment === null || $line->compensationAmount()->minor <= 0) {
            return;
        }

        $claim = CourierLossClaim::query()->where('consignment_id', $consignment->id)->lockForUpdate()->first();

        if ($claim === null || $claim->isClosed()) {
            return;
        }

        $claim->forceFill([
            'compensation_received' => Money::ofMinor(Money::ofTaka((string) $claim->compensation_received)->minor + $line->compensationAmount()->minor)->toTaka(),
            'courier_statement_line_id' => $line->id,
            'status' => CourierLossClaim::PAID,
            'closed_by' => $by->id,
            'closed_at' => now(),
        ])->save();
    }

    private function assertOpen(CourierLossClaim $claim): void
    {
        if ($claim->isClosed()) {
            throw ValidationException::withMessages(['claim' => "This claim is already closed ({$claim->statusLabel()})."]);
        }
    }

    private function withNote(CourierLossClaim $claim, ?string $note): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return $claim->notes;
        }

        return trim(($claim->notes ? $claim->notes."\n" : '').now()->toDateString().': '.$note);
    }
}
