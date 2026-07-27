<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Jobs\RenderReportJob;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportParameterType;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\WriterRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one screen that runs every report (W3).
 *
 * There is deliberately no per-report controller. A definition declares its
 * parameters and columns; this builds the form, validates the input, resolves
 * the ids to labels so the rendered report can state what produced it, and
 * hands the result to a writer. Fifteen reports, one controller — the whole
 * point of the framework.
 *
 * **Access (Correction C10d)** is enforced through `ReportRegistry`, which
 * checks the declared permission *and* re-applies ACL-02: a Department Manager
 * gets no payroll or salary report at all. A report a user may not run 404s
 * rather than 403s, so enumerating report keys tells nobody what exists.
 *
 * **Every run is activity-logged with its parameters** — the cheapest available
 * down payment on W4's SEC-06 read-audit, since "who viewed salary data" is
 * largely "who ran the salary sheet, for which period".
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly WriterRegistry $writers,
        private readonly ReportRunner $runner,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $available = $this->reports->availableTo($request->user());

        return Inertia::render('hrm/reports/index', [
            'groups' => $available
                ->groupBy(fn (ReportDefinition $d) => $d->group())
                ->map(fn ($group) => $group->map(fn (ReportDefinition $d) => [
                    'key' => $d->key(),
                    'name' => $d->name(),
                    'description' => $d->description(),
                ])->values())
                ->toArray(),
        ]);
    }

    public function show(Request $request, string $report): InertiaResponse
    {
        $definition = $this->reports->findOrFail($report, $request->user());

        return Inertia::render('hrm/reports/show', [
            'report' => [
                'key' => $definition->key(),
                'name' => $definition->name(),
                'description' => $definition->description(),
                'group' => $definition->group(),
            ],
            'parameters' => array_map(
                fn (ReportParameter $p) => $this->presentParameter($p),
                $definition->parameters(),
            ),
            'columns' => array_map(fn ($c) => $c->toArray(), $definition->columns()),
            'formats' => $this->writers->options(),
            'queue_threshold' => $this->runner->threshold(),
        ]);
    }

    /**
     * Run it. Either the file comes back now, or — over Correction C10e's
     * threshold — the job takes it and the user is told where it will appear.
     */
    public function run(Request $request, string $report): BinaryFileResponse|RedirectResponse|Response
    {
        $definition = $this->reports->findOrFail($report, $request->user());

        $validated = $request->validate(
            array_merge($this->rules($definition), ['format' => 'required|string']),
        );

        $writer = $this->writers->findOrFail($validated['format']);
        $parameters = $this->parameters($definition, $validated);

        activity('hrm.report')
            ->causedBy($request->user())
            ->withProperties([
                'report' => $definition->key(),
                'format' => $writer->key(),
                'parameters' => $parameters->all(),
            ])
            ->log('Ran report: '.$definition->name());

        // C10e. The estimate is taken before a single row is produced; over the
        // threshold this becomes somebody else's problem, on the queue.
        if ($writer->isDownload() && $this->runner->shouldQueue($definition, $parameters)) {
            RenderReportJob::dispatch(
                $definition->key(),
                $writer->key(),
                $parameters->all(),
                $request->user()->id,
                // Captured here, while the request still has it. The job cannot
                // recover it after serialisation — see the job's docblock.
                app(BusinessContext::class)->id(),
            );

            return back()->with('status', sprintf(
                'This report is large (about %s rows), so it is being prepared in the background. You will be notified when it is ready to download.',
                number_format($this->runner->estimate($definition, $parameters)),
            ));
        }

        $dataset = $this->runner->run($definition, $parameters, $request->user());
        $path = $writer->write($dataset);

        if (! $writer->isDownload()) {
            // The print view is a page, not a file. Sending it as an attachment
            // would have the browser save it, and a print view that cannot be
            // printed has no reason to exist.
            $html = (string) file_get_contents($path);
            @unlink($path);

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response()
            ->download($path, $this->runner->filename($definition, $writer->extension()), [
                'Content-Type' => $writer->mimeType(),
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Collect a report a queued run has already produced.
     *
     * Files live under the acting user's own folder and the path is rebuilt
     * from the signed-in user rather than taken from the URL — so a guessed
     * filename cannot reach another user's export. W3a stores these on disk
     * without a database row on purpose: a table to track them earns its keep
     * only once expiry, sharing or re-download history is asked for, and none
     * of that has been.
     */
    public function download(Request $request, string $file): BinaryFileResponse
    {
        $path = sprintf('reports/%d/%s', $request->user()->id, basename($file));

        if (! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException('That report is no longer available.');
        }

        return response()->download(Storage::disk('local')->path($path));
    }

    /**
     * The validation rules the definition's own parameters imply, plus the
     * option lists the form needs. No report writes either.
     *
     * @return array<string, string>
     */
    private function rules(ReportDefinition $definition): array
    {
        $rules = [];

        foreach ($definition->parameters() as $parameter) {
            $rules = array_merge($rules, $parameter->rules());
        }

        return $rules;
    }

    /**
     * Turn validated input into a parameter bag that also knows how to describe
     * itself. The labels are resolved here, while the option lists are still in
     * hand — a report handed over three weeks later must say which office and
     * which period produced it, and `office_id = 4` says nothing.
     *
     * @param  array<string, mixed>  $validated
     */
    private function parameters(ReportDefinition $definition, array $validated): ReportParameters
    {
        $parameters = new ReportParameters($validated);

        foreach ($definition->parameters() as $parameter) {
            $label = $this->describe($parameter, $validated);

            if ($label !== null) {
                $parameters = $parameters->withLabel($parameter->label, $label);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function describe(ReportParameter $parameter, array $validated): ?string
    {
        if ($parameter->type->isRange()) {
            $from = $validated["{$parameter->key}_from"] ?? null;
            $to = $validated["{$parameter->key}_to"] ?? null;

            return $from === null && $to === null ? null : sprintf('%s to %s', $from, $to);
        }

        $value = $validated[$parameter->key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return match ($parameter->type) {
            ReportParameterType::Office => Office::find($value)?->name,
            ReportParameterType::Department => Department::find($value)?->name,
            ReportParameterType::Employee => Employee::find($value)?->fullName(),
            ReportParameterType::PayrollRun => $this->describeRun((int) $value),
            ReportParameterType::Period => PayrollPeriod::find($value)?->label(),
            ReportParameterType::Boolean => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    private function describeRun(int $id): ?string
    {
        $run = PayrollRun::with('period')->find($id);

        return $run === null ? null : sprintf('%s (v%d)', (string) $run->period?->label(), $run->version);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentParameter(ReportParameter $parameter): array
    {
        $presented = $parameter->toArray();

        // The framework supplies the option lists for the types that have a
        // fixed source, so a definition never queries for a dropdown.
        $presented['options'] = match ($parameter->type) {
            ReportParameterType::Office => Office::orderBy('name')->get(['id', 'name'])
                ->map(fn (Office $o) => ['value' => $o->id, 'label' => $o->name])->all(),
            ReportParameterType::Department => Department::orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])->all(),
            ReportParameterType::Employee => Employee::orderBy('first_name')->get()
                ->map(fn (Employee $e) => ['value' => $e->id, 'label' => $e->fullName().' ('.$e->employee_code.')'])->all(),
            ReportParameterType::PayrollRun => PayrollRun::with('period')->orderByDesc('id')->limit(60)->get()
                ->map(fn (PayrollRun $r) => [
                    'value' => $r->id,
                    'label' => sprintf('%s — v%d (%s)', (string) $r->period?->label(), $r->version, $r->status->label()),
                ])->all(),
            ReportParameterType::Period => PayrollPeriod::orderByDesc('year')->orderByDesc('month')->limit(36)->get()
                ->map(fn (PayrollPeriod $p) => ['value' => $p->id, 'label' => $p->label()])->all(),
            default => $parameter->options,
        };

        return $presented;
    }
}
