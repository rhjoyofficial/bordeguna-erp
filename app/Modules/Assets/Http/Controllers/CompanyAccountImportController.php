<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\ApplyCompanyAccountImport;
use App\Modules\Assets\Actions\PlanCompanyAccountImport;
use App\Modules\Assets\Enums\CompanyAccountKind;
use App\Modules\Assets\Support\CompanyAccountFile;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounts importer, as a screen (slice 4): template, upload, preview,
 * apply. The same three steps as the product import, over the same staging.
 */
class CompanyAccountImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('assets/accounts/import', ['preview' => null] + $this->pageProps());
    }

    /**
     * The template with its columns and two example rows. The examples name
     * no real employee: codes are the person's own to fill in.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, CompanyAccountFile::TEMPLATE);
            fputcsv($out, ['domain', 'example.com', 'Nikolahost', 'example.com', 'https://asiacp2000.nikolahost.com', 'BDG-000016', '', '2027-02-01', '1800', 'active', '', '', '', 'Renews yearly']);
            fputcsv($out, ['email', 'Support mailbox', 'Hostinger', 'support@example.com', 'https://mail.hostinger.com', 'BDG-000016', 'BDG-000012;BDG-000019', '', '', 'active', '', '', 'BDG-000016', 'Shared by the CS team']);
            fclose($out);
        }, 'accounts-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Upload, and show what it WOULD do. Writes nothing. */
    public function preview(Request $request, PlanCompanyAccountImport $planner): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'CSV file']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '.number_format(StagedImportFile::MAX_ROWS).'.',
            ]);
        }

        try {
            $rows = CompanyAccountFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($rows);

        return Inertia::render('assets/accounts/import', $this->pageProps() + [
            'preview' => [
                'token' => $staged->token,
                'hash' => $staged->hash,
                'file_name' => $staged->originalName,
                'rows' => $staged->rows,
                'errors' => $plan->errors,
                'warnings' => $plan->warnings,
                'creates' => array_map(fn ($c) => ['line' => $c['line'], 'name' => $c['name'], 'kind' => $c['kind'], 'users' => count($c['users'])], $plan->creates),
                'updates' => array_map(fn ($u) => [
                    'line' => $u['line'], 'name' => $u['name'], 'kind' => $u['kind'],
                    'changes' => collect($u['changes'])->map(fn ($pair, $field) => [
                        'field' => $field, 'from' => (string) $pair[0], 'to' => (string) $pair[1],
                    ])->values()->all(),
                    'new_users' => $u['new_users'],
                ], $plan->updates),
                'unchanged' => $plan->unchanged,
            ],
        ]);
    }

    /**
     * Re-read the file, prove it is the reviewed one, apply it in one
     * transaction.
     */
    public function store(Request $request, PlanCompanyAccountImport $planner, ApplyCompanyAccountImport $applier): RedirectResponse
    {
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

        // ⚠⚠ Re-planned from the bytes on disk, never from the form.
        $plan = $planner->handle(CompanyAccountFile::read($staged->path));

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so nothing was imported. Fix them and upload it again.',
            ]);
        }

        $done = $applier->handle($plan, $request->user());
        $staged->discard($businessId);

        return redirect()->route('assets.accounts.index')
            ->with('success', "{$done['created']} account(s) created, {$done['updated']} updated, {$done['access']} access row(s) added.");
    }

    /**
     * @return array<string, mixed>
     */
    private function pageProps(): array
    {
        return [
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
            'columns' => [
                'mandatory' => CompanyAccountFile::MANDATORY,
                'optional' => CompanyAccountFile::OPTIONAL,
            ],
            'kinds' => array_map(fn (CompanyAccountKind $kind) => $kind->value, CompanyAccountKind::cases()),
        ];
    }
}
