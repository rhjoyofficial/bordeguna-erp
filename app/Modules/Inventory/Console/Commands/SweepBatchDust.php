<?php

namespace App\Modules\Inventory\Console\Commands;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\PostStockCostDifference;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ⭐ 26 Oct 2026, one-off: clears the paisa left on batches that hold nothing.
 *
 * Before batches carried their exact value (migration 2026_10_26_400000), the
 * goods of a batch whose cost a landed cost had changed left at quantity x a
 * unit cost rounded to 4 places, so a batch that sold out could keep a few
 * paisa of value with no units, and Stock on Hand carried that dust forever.
 * Since then the last issue from a batch takes exactly what is left, so new
 * dust cannot appear; this cleans what is already there.
 *
 * Dry run by default: lists every empty batch with value, and how many batches
 * that still hold stock carry a value other than quantity x unit cost (that is
 * expected after a landed cost: the value is exact, the unit cost a display
 * figure). With --apply, each empty batch's dust leaves through:
 *
 *   - a value-only movement on the batch (RevalueStockBatch), so the batch and
 *     the movements say nothing is left; and
 *   - a journal through the posting engine, TODAY, between Stock on Hand and
 *     head 5180 Stock Cost Corrections on Goods Sold (PostStockCostDifference,
 *     the head the Tk 10,000 sold-stock rule already uses): the paisa belonged
 *     to goods already sold.
 *
 * One batch per transaction, all or nothing for that batch. Runs cold, so it
 * enters each business (the BusinessScope trap VerifyStockLedger names).
 */
class SweepBatchDust extends Command
{
    protected $signature = 'inventory:sweep-batch-dust
                            {--apply : Post the dust to head 5180 (otherwise only list it)}
                            {--by= : The user id or email the movements and journals are recorded under (needed with --apply)}
                            {--reason=Value left on an empty batch after a landed cost, cleared to stock cost corrections : Why, written on every movement and journal}';

    protected $description = 'List (or with --apply, clear to head 5180) the value left on stock batches that hold nothing';

    public function handle(RevalueStockBatch $revalue, PostStockCostDifference $post): int
    {
        $apply = (bool) $this->option('apply');
        $reason = trim((string) $this->option('reason'));
        $by = null;

        if ($apply) {
            $key = (string) $this->option('by');
            $by = $key === '' ? null : User::query()->where(is_numeric($key) ? 'id' : 'email', $key)->first();

            if ($by === null) {
                $this->error('Name the person this is recorded under: --by=<user id or email>.');

                return self::FAILURE;
            }

            if (mb_strlen($reason) < 5) {
                $this->error('Give a reason of at least 5 characters.');

                return self::FAILURE;
            }
        }

        $found = 0;
        $failed = 0;

        foreach (Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            $drifted = StockBatch::query()->where('current_quantity', '>', 0)->get(['id', 'current_quantity', 'unit_cost', 'current_value'])
                ->filter(fn (StockBatch $b) => Money::ofProduct((string) $b->current_quantity, (string) $b->unit_cost)->minor !== (int) $b->current_value)
                ->count();

            $this->line("{$business->name}: {$drifted} batch(es) holding stock are worth other than quantity x unit cost (expected after a landed cost; the value is exact).");

            $dusty = StockBatch::query()->where('current_quantity', 0)->where('current_value', '!=', 0)->with('product:id,name')->orderBy('id')->get();

            foreach ($dusty as $batch) {
                $found++;
                $label = "batch #{$batch->id} ".($batch->batch_number ?? '')." ({$batch->product?->name})";
                $this->line("  {$label}: holds nothing but is worth {$batch->value()->format()}");

                if (! $apply) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($batch, $by, $reason, $revalue, $post, $label) {
                        $current = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                        $dust = StockLedger::valueForBatch($current);

                        // Re-checked under the lock against the movements, not the cache.
                        if (bccomp((string) $current->current_quantity, '0', 4) !== 0 || $dust === 0) {
                            return;
                        }

                        $revalue->handle(
                            batch: $current,
                            direction: $dust > 0 ? MovementDirection::Out : MovementDirection::In,
                            newUnitCost: (string) $current->unit_cost,
                            valueDelta: Money::ofMinor(abs($dust))->toTaka(),
                            performedBy: $by->id,
                            note: $reason,
                            occurredAt: Carbon::now(),
                            reason: MovementReason::Adjustment,
                        );

                        // Positive dust over-states stock: Dr 5180 / Cr Stock on
                        // Hand, which PostStockCostDifference writes for a
                        // negative difference.
                        $post->handle($current, "Batch {$current->id}", -$dust, '0', "{$reason} ({$label})", $by);
                    });

                    $this->info("    cleared {$batch->value()->format()} to stock cost corrections");
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("    not cleared: {$e->getMessage()}");
                }
            }
        }

        TenantScope::leave();

        if ($found === 0) {
            $this->info('No empty batch carries any value.');
        } elseif (! $apply) {
            $this->warn("{$found} empty batch(es) carry value. Nothing changed (dry run). Run again with --apply --by=<user> to clear them to head 5180.");
        }

        if ($apply) {
            activity('inventory.ledger')
                ->withProperties(['batches' => $found, 'failed' => $failed, 'by' => $by?->id])
                ->log("Swept the value left on {$found} empty stock batch(es) to stock cost corrections");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
