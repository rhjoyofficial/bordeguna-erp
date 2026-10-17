<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\OwnerWithdrawalPricing;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Authorises a draft owner withdrawal (Phase 2, 17 Oct 2026, D2: every
 * withdrawal authorised and audited, no monthly cap).
 *
 * WHO: a second person holding `owner_withdrawal.authorise` (Finance, the
 * Manager), or the proprietor himself WITH A NOTE saying why nobody else
 * signed (self_authorised, visible on the list and the monthly report).
 *
 * WHAT IT DOES (ruling Q1): for each line, reserves the FIFO batch(es) that
 * will be issued from the warehouse, freezes that batch's unit cost on the
 * line, and snapshots the reference price (MRP, else the selling price in
 * force) with the difference. A quantity the FIFO head cannot cover is split
 * into one line per batch, each at its own cost: never averaged, never the
 * newest batch's cost. Posting issues exactly these batches.
 */
class AuthoriseOwnerWithdrawal
{
    public const PERMISSION = 'owner_withdrawal.authorise';

    public function __construct(
        private readonly ReserveStock $reserve,
        private readonly OwnerWithdrawalPricing $pricing,
    ) {}

    public function handle(OwnerWithdrawal $withdrawal, User $by, ?string $note = null): OwnerWithdrawal
    {
        if (! $withdrawal->isDraft()) {
            throw new RuntimeException("Owner withdrawal {$withdrawal->number} is already ".strtolower($withdrawal->status->label()).'.');
        }

        $self = (int) $withdrawal->proprietor_user_id === (int) $by->id;
        $note = trim((string) $note);

        if ($self && $note === '') {
            throw ValidationException::withMessages(['authorisation_note' => 'The proprietor may authorise his own withdrawal only with a note saying why no second person signed.']);
        }

        if (! $self && ! $by->can(self::PERMISSION)) {
            throw new RuntimeException('An owner withdrawal is authorised by a second person (Finance or the Manager), or by the proprietor himself with a note.');
        }

        return DB::transaction(function () use ($withdrawal, $by, $note, $self) {
            $withdrawal = OwnerWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->with(['lines.product', 'warehouse'])->firstOrFail();

            if (! $withdrawal->isDraft()) {
                throw new RuntimeException("Owner withdrawal {$withdrawal->number} was authorised a moment ago.");
            }

            if ($withdrawal->lines->isEmpty()) {
                throw new RuntimeException("Owner withdrawal {$withdrawal->number} has no lines to authorise.");
            }

            $on = Carbon::parse($withdrawal->withdrawn_on)->startOfDay();
            $costTotal = Money::zero();
            $referenceTotal = Money::zero();
            $anyReference = false;
            $sort = 0;

            foreach ($withdrawal->lines as $line) {
                /** @var OwnerWithdrawalLine $line */
                $reservations = $this->reserve->handle($line->product, $withdrawal->warehouse, (string) $line->quantity, 'owner_withdrawal', $withdrawal, $by);
                $reference = $this->pricing->reference($line->product, (string) $line->quantity, $on);

                foreach ($reservations->values() as $index => $reservation) {
                    /** @var StockReservation $reservation */
                    $batch = $reservation->batch;
                    $quantity = (string) $reservation->quantity;
                    $cost = Money::ofProduct($quantity, (string) $batch->unit_cost);
                    $referenceMoney = $reference['price'] === null ? null : Money::ofProduct($quantity, $reference['price']);

                    $attributes = [
                        'sort' => $sort++,
                        'quantity' => $quantity,
                        'stock_reservation_id' => $reservation->id,
                        'stock_batch_id' => $batch->id,
                        'unit_cost' => (string) $batch->unit_cost,
                        'cost_paisa' => $cost->minor,
                        'reference_source' => $reference['source'],
                        'reference_price' => $reference['price'],
                        'reference_paisa' => $referenceMoney?->minor,
                        'difference_paisa' => $referenceMoney === null ? null : $referenceMoney->plus($cost->negated())->minor,
                    ];

                    if ($index === 0) {
                        $line->update($attributes);
                    } else {
                        // ⚠ The FIFO head could not cover the whole quantity: the
                        // rest comes from the next batch at ITS cost, on its own line.
                        $withdrawal->lines()->create($attributes + [
                            'business_id' => $withdrawal->business_id,
                            'product_id' => $line->product_id,
                            'note' => trim(($line->note ?? '').' (split: second batch)'),
                        ]);
                    }

                    $costTotal = $costTotal->plus($cost);

                    if ($referenceMoney !== null) {
                        $anyReference = true;
                        $referenceTotal = $referenceTotal->plus($referenceMoney);
                    }
                }
            }

            $withdrawal->update([
                'status' => OwnerWithdrawalStatus::Approved,
                'authorised_by' => $by->id,
                'authorised_at' => now(),
                'authorisation_note' => $note !== '' ? $note : null,
                'self_authorised' => $self,
                'reapproval_reason' => null,
                'cost_total_paisa' => $costTotal->minor,
                'reference_total_paisa' => $anyReference ? $referenceTotal->minor : null,
            ]);

            return $withdrawal->fresh(['lines.product', 'lines.batch', 'reservations']);
        });
    }
}
