<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\ApplyCustomerImport;
use App\Modules\Sales\Actions\PlanCustomerImport;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CustomerCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The customer list in and out of a spreadsheet.
 *
 * ⚠⚠ THE ACTIONS DECIDE EVERYTHING; this controller moves a file. A second set
 * of rules here would be a second importer that disagrees with the first about
 * what a valid file is, and the operator would have no way to know which one
 * was right.
 *
 * ⚠ Review is not optional and not a checkbox. An upload always produces a
 * plan; writing it takes a second, separate request that the person makes after
 * reading what would happen.
 */
class CustomerImportController extends Controller
{
    /** Where reviewed rows wait between the two requests. */
    private const SESSION_KEY = 'customer_import_rows';

    /**
     * A blank file with the right header, so nobody has to guess the columns.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print CustomerCsv::template(),
            'customer-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Everything currently in the business, in a file the importer accepts back.
     */
    public function export(): StreamedResponse
    {
        // ⚠ Read INSIDE the closure. streamDownload defers execution, and the
        // query must run while the request's tenant scope is still in force.
        $customers = Customer::query()->with('priceGroup')->orderBy('code')->get();

        return response()->streamDownload(
            fn () => print CustomerCsv::export($customers),
            'customers-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Step one: read the file, plan it, show what would happen. Writes nothing.
     */
    public function review(Request $request, PlanCustomerImport $planner): RedirectResponse
    {
        $request->validate([
            // ⚠ `txt` is accepted because a CSV exported on Windows and mailed
            // around arrives as text/plain often enough that refusing it only
            // makes people rename the file and try again.
            'file' => ['required', 'file', 'max:4096', 'mimes:csv,txt'],
        ]);

        try {
            $handle = fopen($request->file('file')->getRealPath(), 'r');
            $rows = CustomerCsv::readHandle($handle);
            fclose($handle);
        } catch (Throwable $e) {
            // A header problem is fatal and has nothing to do with any row.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'That file has a valid header and no rows.']);
        }

        $plan = $planner->handle($rows);

        // ⚠⚠ The ROWS are kept, not the plan. A plan holds model ids and
        // computed changes that may be stale by the time the person confirms;
        // re-planning at the moment of writing means the confirmation is
        // checked against the list as it is THEN, not as it was.
        // CRM-014 follow-up (audit 14 Oct 2026): kept WITH the business it
        // was reviewed in. Applying after a business switch used to write the
        // file into a list whose diff nobody had read.
        $request->session()->put(self::SESSION_KEY, ['business_id' => app(BusinessContext::class)->id(), 'rows' => $rows]);

        return back()->with('customerImportPlan', [
            'rowCount' => count($rows),
            'creates' => array_map(fn ($c) => ['line' => $c['line'], 'code' => $c['code'], 'name' => $c['name']], $plan->creates),
            'updates' => array_map(fn ($u) => [
                'line' => $u['line'], 'code' => $u['code'], 'name' => $u['name'],
                'changes' => array_map(
                    fn ($field, $pair) => $field.': '.$this->readable($pair[0]).' -> '.$this->readable($pair[1]),
                    array_keys($u['changes']), $u['changes'],
                ),
            ], $plan->updates),
            'unchanged' => count($plan->unchanged),
            'absentFromFile' => $plan->absentFromFile,
            'warnings' => array_map(fn ($w) => $this->where($w).' '.$w['message'], $plan->warnings),
            'errors' => array_map(fn ($e) => $this->where($e).' '.$e['message'], $plan->errors),
        ]);
    }

    /**
     * Step two: write the plan the person has just read.
     */
    public function apply(Request $request, PlanCustomerImport $planner, ApplyCustomerImport $applier): RedirectResponse
    {
        $waiting = $request->session()->pull(self::SESSION_KEY);
        $rows = is_array($waiting) ? ($waiting['rows'] ?? null) : null;

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'file' => 'There is nothing waiting to import. Upload the file again.',
            ]);
        }

        // CRM-014 follow-up (14 Oct 2026): only in the business it was reviewed in.
        if (($waiting['business_id'] ?? null) !== app(BusinessContext::class)->id()) {
            throw ValidationException::withMessages([
                'file' => 'That file was reviewed in another business. Upload it again here to see what it would change.',
            ]);
        }

        // ⚠⚠ RE-PLANNED against the list as it is NOW. Somebody may have added
        // a customer on another screen since the review, and applying a stale
        // plan would either fail on a duplicate code or quietly overwrite work
        // done in between.
        $plan = $planner->handle($rows);

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'The file no longer imports cleanly: '.$plan->errors[0]['message'],
            ]);
        }

        if (! $plan->wouldChangeAnything()) {
            return back()->with('success', 'Nothing to change. The customer list already matches the file.');
        }

        try {
            $applier->handle($plan);
        } catch (RuntimeException $e) {
            // Both refusals used to be blank 500s. The upload panel renders
            // `file`, so that is where they belong.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Imported: %d created, %d updated.',
            count($plan->creates), count($plan->updates),
        ));
    }

    /**
     * @param  array{line:int, code:string, message:string}  $issue
     */
    private function where(array $issue): string
    {
        return $issue['line'] === 0 ? "[{$issue['code']}]" : "Line {$issue['line']} [{$issue['code']}]";
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(none)',
            $value === true => 'active',
            $value === false => 'inactive',
            $value instanceof CustomerType => $value->label(),
            default => (string) $value,
        };
    }
}
