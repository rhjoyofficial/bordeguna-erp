<?php

namespace App\Modules\Commerce\Console\Commands;

use App\Modules\Commerce\Actions\CheckGatewayRefund;
use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * Follow every refund sent through SSLCommerz until it is finished and booked
 * (slice P4): ask again about one SSLCommerz never answered, ask where an
 * accepted one stands, and book one it has confirmed.
 *
 * ⚠ A refund left `requested` is asked for again under the SAME
 * `refund_trans_id`, the id SSLCommerz uses to tell one refund of a payment from
 * another. A new id is never minted here.
 */
class FollowGatewayRefunds extends Command
{
    protected $signature = 'commerce:gateway-refunds
        {--business= : Limit to one business id}
        {--dry-run : Say what would be asked, and change nothing}';

    protected $description = 'Ask SSLCommerz about every refund not yet finished, and book the ones it has confirmed';

    public function handle(CheckGatewayRefund $check): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $open = GatewayRefund::query()
                ->whereIn('status', array_map(fn (GatewayRefundStatus $status) => $status->value, GatewayRefundStatus::inFlight()))
                ->orderBy('id')
                ->get();

            $counts = [];

            foreach ($open as $refund) {
                if ($dryRun) {
                    $this->line("  would check {$refund->refund_trans_id} ({$refund->status->value})");

                    continue;
                }

                try {
                    $after = $check->handle($refund);
                } catch (Throwable $e) {
                    $this->warn("  {$refund->refund_trans_id} not checked: {$e->getMessage()}");

                    continue;
                } finally {
                    // ⚠ Booking enters the refund's business; come back to ours.
                    TenantScope::enter($business->id);
                }

                $counts[$after->status->value] = ($counts[$after->status->value] ?? 0) + 1;

                if ($after->problem) {
                    $this->warn("  {$after->refund_trans_id}: {$after->problem}");
                }
            }

            $summary = $counts === [] ? 'nothing to check' : collect($counts)->map(fn ($n, $status) => "{$n} {$status}")->implode(', ');
            $this->line("{$business->name}: {$summary}");
        }

        TenantScope::leave();

        return self::SUCCESS;
    }
}
