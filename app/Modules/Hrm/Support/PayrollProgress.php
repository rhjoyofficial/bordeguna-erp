<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Facades\Cache;

/**
 * Live progress for a queued payroll calculation (WFL-04 asks for visible
 * progress).
 *
 * Deliberately in the cache rather than a database column. WFL-09 requires the
 * whole batch to succeed or roll back, so the calculation runs inside one
 * transaction — and a progress column written inside that transaction would
 * only become visible when it commits, by which point the run has finished and
 * progress is worthless. The cache is outside the transaction, so the UI can
 * watch it move.
 *
 * It is also expendable: if it is lost the run is unaffected, because the
 * authoritative record is the run's own status and counts.
 */
class PayrollProgress
{
    private const TTL_MINUTES = 60;

    /**
     * @return array{state: string, processed: int, total: int}
     */
    public function for(PayrollRun $run): array
    {
        return Cache::get($this->key($run), [
            'state' => $run->queue_state ?? 'idle',
            'processed' => 0,
            'total' => $run->employee_count,
        ]);
    }

    public function start(PayrollRun $run, int $total): void
    {
        $this->put($run, 'validating', 0, $total);
    }

    public function calculating(PayrollRun $run, int $processed, int $total): void
    {
        $this->put($run, 'calculating', $processed, $total);
    }

    public function finish(PayrollRun $run, int $total): void
    {
        $this->put($run, 'done', $total, $total);
    }

    public function fail(PayrollRun $run): void
    {
        $current = $this->for($run);
        $this->put($run, 'failed', $current['processed'], $current['total']);
    }

    public function clear(PayrollRun $run): void
    {
        Cache::forget($this->key($run));
    }

    private function put(PayrollRun $run, string $state, int $processed, int $total): void
    {
        Cache::put($this->key($run), [
            'state' => $state,
            'processed' => $processed,
            'total' => $total,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(PayrollRun $run): string
    {
        return "payroll:progress:{$run->id}";
    }
}
