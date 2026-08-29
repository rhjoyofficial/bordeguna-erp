<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Actions\ApplyContactImport;
use App\Modules\Crm\Actions\PlanContactImport;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Support\ContactCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The phone book in and out of a spreadsheet.
 *
 * ⚠⚠ THE ACTIONS DECIDE EVERYTHING; this controller moves a file. A second set
 * of rules here would be a second importer that disagrees with the first about
 * what a valid file is, and the operator would have no way to know which one was
 * right.
 *
 * ⚠ Review is not optional and not a checkbox. An upload always produces a plan;
 * writing it takes a second, separate request that the person makes after
 * reading what would happen. This is Joy's standing rule for every importer in
 * the system, and it matters more here than anywhere: the phone book is what a
 * future send is aimed at, and a bad import is money once SMS is wired.
 */
class ContactImportController extends Controller
{
    /** Where reviewed rows wait between the two requests. */
    private const SESSION_KEY = 'contact_import_rows';

    /**
     * A blank file with the right header, so nobody has to guess the columns.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print ContactCsv::template(),
            'phone-book-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Everything currently in the book, in a file the importer accepts back.
     */
    public function export(): StreamedResponse
    {
        return response()->streamDownload(
            // ⚠ Read INSIDE the closure. streamDownload defers execution, and
            // the query must run while the request's tenant scope is in force.
            fn () => print ContactCsv::export(Contact::query()->orderBy('name')->get()),
            'phone-book-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Step one: read the file, plan it, show what would happen. Writes nothing.
     */
    public function review(Request $request, PlanContactImport $planner): RedirectResponse
    {
        $request->validate([
            // ⚠ `txt` is accepted because a CSV exported on Windows and mailed
            // around arrives as text/plain often enough that refusing it only
            // makes people rename the file and try again.
            'file' => ['required', 'file', 'max:4096', 'mimes:csv,txt'],
        ]);

        try {
            $handle = fopen($request->file('file')->getRealPath(), 'r');
            $rows = ContactCsv::readHandle($handle);
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
        // re-planning at the moment of writing means the confirmation is checked
        // against the book as it is THEN, not as it was.
        $request->session()->put(self::SESSION_KEY, $rows);

        return back()->with('contactImportPlan', [
            'rowCount' => count($rows),
            'creates' => array_map(fn ($c) => ['line' => $c['line'], 'name' => $c['name'], 'phone' => $c['phone']], $plan->creates),
            'updates' => array_map(fn ($u) => [
                'line' => $u['line'], 'name' => $u['name'], 'phone' => $u['phone'],
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
    public function apply(Request $request, PlanContactImport $planner, ApplyContactImport $applier): RedirectResponse
    {
        $rows = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'file' => 'There is nothing waiting to import. Upload the file again.',
            ]);
        }

        // ⚠⚠ RE-PLANNED against the book as it is NOW. Somebody may have added a
        // contact on another screen since the review, and applying a stale plan
        // would either duplicate a number or quietly overwrite work done in
        // between.
        $plan = $planner->handle($rows);

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'The file no longer imports cleanly: '.$plan->errors[0]['message'],
            ]);
        }

        if (! $plan->wouldChangeAnything()) {
            return back()->with('success', 'Nothing to change. The phone book already matches the file.');
        }

        try {
            $applier->handle($plan);
        } catch (RuntimeException $e) {
            // The upload panel renders `file`, so a refusal belongs there rather
            // than becoming a blank 500.
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Imported: %d created, %d updated.',
            count($plan->creates), count($plan->updates),
        ));
    }

    /**
     * @param  array{line:int, name:string, message:string}  $issue
     */
    private function where(array $issue): string
    {
        return $issue['name'] === ''
            ? "Line {$issue['line']}"
            : "Line {$issue['line']} [{$issue['name']}]";
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(none)',
            $value === true => 'active',
            $value === false => 'inactive',
            default => (string) $value,
        };
    }
}
