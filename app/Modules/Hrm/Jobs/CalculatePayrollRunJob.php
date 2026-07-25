<?php

namespace App\Modules\Hrm\Jobs;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Payroll\PayrollConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The queued payroll calculation (WFL-04: heavy calculation runs as a queued
 * job and reports progress).
 *
 * This is the first real consumer of the database queue that has been
 * configured but unused since the ERP track.
 *
 * Two things the queue makes necessary and are easy to get wrong: the tenant
 * context does not survive serialisation, so the business is restored
 * explicitly here; and the authenticated user does not either, so the acting
 * user is carried and re-established — otherwise the run would record no
 * calculator and every audit entry would be anonymous.
 *
 * There are no retries. WFL-09 wants the whole batch rolled back and the errors
 * shown before a deliberate retry, not a job quietly attempting the same failing
 * calculation three more times.
 */
class CalculatePayrollRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $businessId,
        public int $periodId,
        public ?int $actingUserId = null,
        /** @var array<string, mixed>|null */
        public ?array $config = null,
    ) {}

    public function handle(BusinessContext $context, CalculatePayroll $calculate): void
    {
        $context->set($this->businessId);

        if ($this->actingUserId !== null) {
            // So `calculated_by` and the activity log name a person rather than
            // "system".
            Auth::loginUsingId($this->actingUserId);
        }

        $period = PayrollPeriod::findOrFail($this->periodId);

        $calculate->handle(
            $period,
            $this->config === null ? null : PayrollConfig::fromArray($this->config),
        );
    }

    /**
     * The action already rolled the transaction back and recorded the failure
     * on the run; this exists so a queue-level failure (a timeout, a killed
     * worker) is not silent either.
     */
    public function failed(Throwable $exception): void
    {
        $period = PayrollPeriod::withoutGlobalScopes()->find($this->periodId);
        $run = $period?->runs()->first();

        $run?->update([
            'queue_state' => 'failed',
            'failure_message' => $exception->getMessage(),
        ]);
    }
}
