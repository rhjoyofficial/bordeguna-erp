<?php

namespace App\Modules\Hrm\Jobs;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Mail\ReportReadyMail;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\WriterRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a report too large to serve inside a request (Correction C10e).
 *
 * ⚠ **The two queue gotchas 3c found the hard way apply here unchanged, and
 * they are the reason this job restores both by hand:**
 *
 *  1. **`BusinessContext` does not survive serialisation.** Without
 *     `$context->set()` the business scope resolves to nothing and the report
 *     silently renders *every business's* rows — a tenancy leak that looks like
 *     a successful export.
 *  2. **The authenticated user does not survive either.** Without
 *     `Auth::loginUsingId()` any permission check inside a definition sees a
 *     guest, and the file lands with no owner.
 *
 * `tries = 1`, deliberately, as in 3c: a report that failed should be re-run by
 * a human who can see why, not retried silently into the same failure.
 */
class RenderReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        private readonly string $reportKey,
        private readonly string $format,
        private readonly array $parameters,
        private readonly int $userId,
        private readonly ?int $businessId = null,
    ) {}

    public function handle(
        ReportRegistry $reports,
        WriterRegistry $writers,
        ReportRunner $runner,
        BusinessContext $context,
    ): void {
        if ($this->businessId !== null) {
            $context->set($this->businessId);
        }

        Auth::loginUsingId($this->userId);
        $user = User::find($this->userId);

        $definition = $reports->findOrFail($this->reportKey);
        $writer = $writers->findOrFail($this->format);

        $dataset = $runner->run($definition, new ReportParameters($this->parameters), $user);
        $temp = $writer->write($dataset);

        $filename = $runner->filename($definition, $writer->extension());
        $path = sprintf('reports/%d/%s', $this->userId, $filename);

        Storage::disk('local')->put($path, (string) file_get_contents($temp));
        @unlink($temp);

        if ($user !== null && $user->email !== null) {
            Mail::to($user->email)->send(new ReportReadyMail($definition->name(), $filename));
        }
    }

}
