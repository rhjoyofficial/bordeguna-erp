<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\ApplyAssetItemImport;
use App\Modules\Assets\Actions\PlanAssetItemImport;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Support\AssetItemFile;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The things sheet (asset register bulk import, Joy 13 Oct 2026): download the
 * template, fill it, upload, read the preview, apply. So the company's things
 * reach the register in one sitting instead of one form at a time, and the
 * sheet can say who holds what today.
 *
 * ⚠ The importers' contract (C-IMP-1/2/3): the preview writes nothing, the
 * apply re-reads the bytes it was shown (StagedImportFile's hash), and a sheet
 * with one error writes nothing at all. Gated with `assets.items.manage`, the
 * same permission that registers a thing on the form; prices only reach the
 * register from someone with `assets.view`, as on the form.
 */
class AssetImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('assets/items/import', $this->pageProps(null));
    }

    /**
     * The header and five example rows, one of each case, so the person filling
     * it in copies a shape rather than reading a manual.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, AssetItemFile::TEMPLATE);

            foreach (AssetItemFile::EXAMPLES as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'things-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Upload, and show what it WOULD do. Writes nothing. */
    public function preview(Request $request, PlanAssetItemImport $planner): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:'.(StagedImportFile::MAX_BYTES / 1024)],
        ], [], ['file' => 'CSV file']);

        $businessId = app(BusinessContext::class)->id();
        $staged = StagedImportFile::stage($request->file('file'), $businessId);

        if ($staged->isTooBig()) {
            $staged->discard($businessId);

            throw ValidationException::withMessages([
                'file' => 'That file has '.number_format($staged->rows).' rows. The limit is '.number_format(StagedImportFile::MAX_ROWS).'. Split it and import the parts.',
            ]);
        }

        try {
            $rows = AssetItemFile::read($staged->path);
        } catch (RuntimeException $e) {
            $staged->discard($businessId);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $plan = $planner->handle($rows, $request->user());

        return Inertia::render('assets/items/import', $this->pageProps([
            'token' => $staged->token,
            'hash' => $staged->hash,
            'file_name' => $staged->originalName,
            'rows' => $staged->rows,
            'errors' => $plan->errors,
            'warnings' => $plan->warnings,
            'tags' => $plan->tagsToCreate(),
            'creates' => array_map(fn (array $c) => [
                'line' => $c['line'],
                'name' => $c['name'],
                'category' => $c['category'],
                // "would create 10 tags" / "1 counted group of 4 pieces".
                'makes' => $c['tracking'] === 'quantity'
                    ? "1 counted group of {$c['pieces']} piece(s)"
                    : ($c['copies'] === 1 ? '1 tag' : "{$c['copies']} tags"),
                'hands_to' => $c['holder'] !== null
                    ? 'would issue to '.$c['holder']['label']
                    : ($c['party'] !== null ? 'would send out to '.$c['party']->label() : null),
            ], $plan->creates),
            'updates' => array_map(fn (array $u) => [
                'line' => $u['line'],
                'tag' => $u['tag'],
                'name' => $u['name'],
                // What would actually change, field by field, so nobody has
                // to guess what "3 to update" means.
                'changes' => collect($u['changes'])->map(fn (array $pair, string $field) => [
                    'field' => $field, 'from' => $pair[0], 'to' => $pair[1],
                ])->values()->all(),
            ], $plan->updates),
        ]));
    }

    /** Re-read the reviewed file, prove it is the same bytes, and apply it. */
    public function store(Request $request, PlanAssetItemImport $planner, ApplyAssetItemImport $applier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'hash' => ['required', 'string'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        try {
            $staged = StagedImportFile::reopen($data['token'], $data['hash'], $businessId);
            // ⚠⚠ Re-planned from the bytes on disk, never from anything carried
            // through the form. The preview is a picture; this is the decision.
            $plan = $planner->handle(AssetItemFile::read($staged->path), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($plan->hasErrors()) {
            throw ValidationException::withMessages([
                'file' => 'That file has '.count($plan->errors).' error(s), so nothing was imported. Fix them and upload it again.',
            ]);
        }

        try {
            $counts = $applier->handle($plan, $request->user());
        } catch (ValidationException $e) {
            // ⚠ A rule the plan could not see (a part-of loop, a tag clash with
            // another business): the transaction rolled back, so say so as one
            // message about the file rather than a field nobody sees.
            throw ValidationException::withMessages(['file' => 'Nothing was imported: '.implode(' ', $e->validator->errors()->all())]);
        }

        $staged->discard($businessId);

        $summary = "{$counts['created']} thing(s) registered, {$counts['updated']} corrected";

        if ($counts['issued'] > 0) {
            $summary .= ", {$counts['issued']} handed to employees";
        }

        if ($counts['sent_out'] > 0) {
            $summary .= ", {$counts['sent_out']} sent to outside parties";
        }

        return redirect()->route('assets.items.index')->with('success', $summary.'.');
    }

    /**
     * Every kept thing in the importer's own columns, so the round trip is
     * export, edit in Excel, upload, preview, apply. Tags are filled, so each
     * row corrects its thing rather than registering it again.
     *
     * ⚠ Prices leave only with someone who may see them (assets.view).
     */
    public function export(Request $request): StreamedResponse
    {
        $seesPrice = $request->user()->can('assets.view');
        $items = AssetItem::query()
            ->with(['category:id,code', 'office:id,name', 'warehouse:id,name', 'parent:id,tag', 'supplier:id,name', 'custodianEmployee:id,employee_code', 'custodianParty:id,name'])
            ->where('status', '!=', AssetStatus::Disposed->value)
            ->orderBy('tag')->get();

        // ⚠ A name that is both an office and a warehouse is written with its
        // kind in front, or the import could not tell which was meant.
        $both = array_intersect(
            Office::query()->pluck('name')->map(fn (string $n) => mb_strtolower($n))->all(),
            Warehouse::query()->pluck('name')->map(fn (string $n) => mb_strtolower($n))->all(),
        );

        return response()->streamDownload(function () use ($items, $seesPrice, $both) {
            $out = fopen('php://output', 'w');

            // ⚠⚠ UTF-8 BOM, or Excel on Windows shows every Bangla name as mojibake.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, AssetItemFile::TEMPLATE);

            foreach ($items as $item) {
                $placeName = $item->office?->name ?? $item->warehouse?->name ?? '';
                $place = in_array(mb_strtolower($placeName), $both, true)
                    ? ($item->office_id ? 'office:' : 'warehouse:').$placeName
                    : $placeName;

                fputcsv($out, [
                    $item->tag,
                    $item->category?->code,
                    $item->name,
                    $item->isIndividual() ? 1 : $item->quantity,
                    $item->brand,
                    $item->model,
                    $item->serial_number,
                    $item->specification,
                    $place,
                    $item->area,
                    $item->status->value,
                    $item->condition->value,
                    $item->parent?->tag,
                    $item->acquired_on?->toDateString(),
                    $item->supplier?->name,
                    $seesPrice && $item->purchase_price !== null ? Money::ofTaka((string) $item->purchase_price)->toTaka() : '',
                    $item->warranty_ends_on?->toDateString(),
                    $item->custodianEmployee?->employee_code,
                    $item->custodianParty?->name,
                    $item->note,
                ]);
            }

            fclose($out);
        }, 'things-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function pageProps(?array $preview): array
    {
        return [
            'preview' => $preview,
            'limits' => [
                'max_rows' => StagedImportFile::MAX_ROWS,
                'max_mb' => (int) (StagedImportFile::MAX_BYTES / 1024 / 1024),
            ],
            'columns' => [
                'mandatory' => AssetItemFile::MANDATORY,
                'optional' => AssetItemFile::OPTIONAL,
            ],
        ];
    }
}
