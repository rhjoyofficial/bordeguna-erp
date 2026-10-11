<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Attention\NeedsAttention;
use App\Support\FailedJobs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Failed jobs (Owner, system.jobs.manage): background jobs that
 * failed, what they were and why, with Retry, Forget and Retry all.
 *
 * ⚠ Payloads never leave the server; exceptions leave scrubbed (FailedJobs).
 * Every action is written to the activity log: retrying a job re-runs whatever
 * it does, a payment sync included.
 */
class FailedJobController extends Controller
{
    public function __construct(
        private readonly NeedsAttention $attention,
        private readonly BusinessContext $context,
    ) {}

    public function index(): Response
    {
        return Inertia::render('settings/failed-jobs', [
            'jobs' => FailedJobs::list(),
            'total' => FailedJobs::count(),
        ]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(FailedJobs::exists($uuid), 404);

        FailedJobs::retry($uuid);
        $this->log($request, 'Retried a failed job', ['uuid' => $uuid]);

        return back()->with('success', 'The job is back on its queue.');
    }

    public function retryAll(Request $request): RedirectResponse
    {
        $count = FailedJobs::count();

        FailedJobs::retryAll();
        $this->log($request, 'Retried every failed job', ['count' => $count]);

        return back()->with('success', "{$count} jobs are back on their queues.");
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(FailedJobs::exists($uuid), 404);

        FailedJobs::forget($uuid);
        $this->log($request, 'Forgot a failed job', ['uuid' => $uuid]);

        return back()->with('success', 'The failed job was removed.');
    }

    private function log(Request $request, string $message, array $properties): void
    {
        activity('system')->causedBy($request->user())->withProperties($properties)->log($message);

        $this->attention->forgetCount($request->user(), (int) $this->context->id());
    }
}
