<?php

namespace App\Modules\Hrm\Jobs;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Mail\ReportReadyMail;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\ReportScope;
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
 *  1. **The tenant does not survive serialisation.** Without entering it the
 *     business scope resolves to nothing and the report silently renders
 *     *every business's* rows, a tenancy leak that looks like a successful
 *     export.
 *  2. **The authenticated user does not survive either.** Without
 *     `Auth::loginUsingId()` any permission check inside a definition sees a
 *     guest, and the file lands with no owner.
 *  3. ⚠⚠ **And the PERMISSION TEAM ID is part of the tenant.** Restoring only
 *     the business was a live ACL-02 bypass until 2026-08-01: roles are
 *     team-scoped, so with no team id `hasRole('Manager')` was false and a
 *     Department Manager's queued report rendered UNSCOPED. Entering through
 *     `TenantScope` is what makes 1 and 3 impossible to separate.
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
     * @param  array<string, string>  $labels  the filter header the controller
     *                                         resolved; the job cannot rebuild it
     */
    public function __construct(
        private readonly string $reportKey,
        private readonly string $format,
        private readonly array $parameters,
        private readonly int $userId,
        private readonly ?int $businessId = null,
        private readonly array $labels = [],
    ) {}

    public function handle(
        ReportRegistry $reports,
        WriterRegistry $writers,
        ReportRunner $runner,
        BusinessContext $context,
    ): void {
        // ⚠⚠ TenantScope, not BusinessContext alone. Setting only the business
        // left spatie with no team id, so `hasRole('Manager')` was false inside
        // the worker, `ReportScope::for()` returned `unrestricted()`, and the
        // C10d re-application below silently became a no-op — the very thing
        // this job's docblock claims it is protecting against.
        if ($this->businessId !== null) {
            TenantScope::enter($this->businessId);
        }

        // ⚠ Loaded AFTER entering the tenant, deliberately. Spatie caches the
        // roles relation on the model instance, so a User read under the wrong
        // team keeps the wrong answer even once the team is corrected.
        Auth::loginUsingId($this->userId);
        $user = User::find($this->userId);

        $definition = $reports->findOrFail($this->reportKey);
        $writer = $writers->findOrFail($this->format);

        // C10d is re-applied here rather than trusted from the dispatch site.
        // The controller already scoped these values, so this is belt and
        // braces — but a queued render is exactly the path where an unscoped
        // export would land in someone's inbox with nobody watching.
        $parameters = new ReportParameters(ReportScope::for($user)->apply($this->parameters), $this->labels);

        $dataset = $runner->run($definition, $parameters, $user);
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
