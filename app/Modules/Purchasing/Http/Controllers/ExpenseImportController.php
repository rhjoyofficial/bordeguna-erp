<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Purchasing\Actions\ApplyExpenseImport;
use App\Modules\Purchasing\Actions\PlanExpenseImport;
use App\Modules\Purchasing\Support\ExpenseImportFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The expense importer, as a screen.
 *
 * ⚠⚠ THE PREVIEW IS NOT THE DECISION. It shows what a file would pay; applying
 * re-plans from the bytes on disk, checked against the fingerprint the preview
 * handed out. Without that, two files previewed in two tabs can apply the wrong
 * one - and the screen would have shown a perfectly truthful preview of a file
 * that is not the one that landed.
 *
 * ⚠ An expense voucher is PAID the moment it is recorded, so this screen says
 * so before the upload rather than after it.
 */
class ExpenseImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('purchasing.expense.record');

        return Inertia::render('purchasing/expenses/import', $this->page(null));
    }

    public function preview(Request $request, PlanExpenseImport $planner): Response
    {
        $this->authorize('purchasing.expense.record');

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'CSV file']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '
                    .number_format(StagedImportFile::MAX_ROWS).'.',
            ]);
        }

        try {
            $read = ExpenseImportFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($read['rows'], $read['ignored']);

        return Inertia::render('purchasing/expenses/import', $this->page([
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $staged->originalName,
            'rows' => $staged->rows,
            'errors' => $plan->errors,
            'warnings' => $plan->warnings,
            'vouchers' => $plan->vouchers,
            'total' => $plan->total(),
            'ignored_columns' => $plan->ignoredColumns,
        ]));
    }

    public function store(Request $request, PlanExpenseImport $planner, ApplyExpenseImport $applier): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $read = ExpenseImportFile::read($staged->path);

        // ⚠⚠ Re-planned from the bytes on disk, never from anything carried
        // through the form. The duplicate check in particular must run against
        // the ledger as it is NOW - somebody may have recorded one of these by
        // hand while the preview sat open.
        $plan = $planner->handle($read['rows'], $read['ignored']);

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so nothing was paid. Review the preview again.',
            ]);
        }

        if ($plan->vouchers === []) {
            throw ValidationException::withMessages(['file' => 'There is nothing in that file to record.']);
        }

        $numbers = $applier->handle($plan, $request->user());
        $staged->discard($businessId);

        return redirect()
            ->route('purchasing.expenses.index')
            ->with('success', count($numbers).' expense(s) recorded, '.number_format($plan->total(), 2).' in total.');
    }

    /**
     * The file this importer reads back, so nobody has to guess the header.
     */
    public function template(): StreamedResponse
    {
        $this->authorize('purchasing.expense.record');

        return response()->streamDownload(
            fn () => print (ExpenseImportFile::template()),
            'expense-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    private function page(?array $preview): array
    {
        return [
            'preview' => $preview,
            'columns' => ExpenseImportFile::COLUMNS,
            // Shown on the page so the two name-matched columns can be spelled
            // correctly the first time rather than after a failed preview.
            'accounts' => Account::query()
                ->where('type', AccountType::Expense)->where('is_postable', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['code' => $a->code, 'label' => $a->label()]),
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->pluck('name'),
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
        ];
    }
}
