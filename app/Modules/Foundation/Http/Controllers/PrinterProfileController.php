<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\PrinterProfile;
use App\Modules\Foundation\Support\ThermalProfile;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the shop describes the paper its counters print on.
 *
 * ⚠ Gated on `foundation.organization.manage`, the same permission that opens
 * Business and Organization settings, and deliberately NOT a new permission of
 * its own. Which printer stands at which counter is company configuration in
 * exactly the way an office or a department is, and a new permission would
 * arrive granted to nobody - so the person who bought the printer could not
 * describe it until somebody edited a role.
 */
class PrinterProfileController extends Controller
{
    /**
     * Sensible starting points, so nobody has to know that an 80mm roll prints
     * 72mm. Chosen from what is actually sold in Bangladesh, not from a
     * catalogue of everything that exists.
     */
    private const PRESETS = [
        [
            'key' => '80mm',
            'label' => '80mm roll (the common counter printer)',
            'paper_width_mm' => 80,
            'print_width_mm' => 72,
            'dpi' => 203,
            'base_font_pt' => 8.0,
        ],
        [
            'key' => '58mm',
            'label' => '58mm roll (small, portable)',
            'paper_width_mm' => 58,
            'print_width_mm' => 48,
            'dpi' => 203,
            'base_font_pt' => 7.0,
        ],
        [
            'key' => '80mm-300',
            'label' => '80mm roll, 300 dpi head',
            'paper_width_mm' => 80,
            'print_width_mm' => 72,
            'dpi' => 300,
            'base_font_pt' => 8.0,
        ],
    ];

    public function index(): Response
    {
        $this->authorize('foundation.organization.manage');

        $profiles = PrinterProfile::query()
            ->with('warehouse:id,name,code')
            ->orderByRaw('warehouse_id IS NOT NULL')
            ->orderBy('name')
            ->get()
            ->map(function (PrinterProfile $profile) {
                $resolved = ThermalProfile::fromModel($profile, $profile->warehouse_id === null ? 'business' : 'outlet');

                return [
                    ...$profile->only([
                        'id', 'warehouse_id', 'name', 'paper_width_mm', 'print_width_mm',
                        'dpi', 'base_font_pt', 'line_height', 'show_logo', 'show_barcode',
                        'footer_note', 'is_active',
                    ]),
                    'warehouse' => $profile->warehouse?->only(['id', 'name', 'code']),
                    // ⭐ The consequences of the numbers above, worked out by the
                    // same code the paper uses. A screen that shows the settings
                    // but not what they DO leaves somebody to discover on the
                    // roll that their barcode does not fit.
                    'barcode_check' => $resolved->barcode('INV-2026-27-0001'),
                    'module_dots' => $resolved->moduleDots(),
                ];
            })
            ->values();

        return Inertia::render('settings/printers', [
            'profiles' => $profiles,
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'presets' => self::PRESETS,
            'builtin' => ThermalProfile::builtin()->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $this->validated($request);
        $this->refuseADuplicate($data['warehouse_id'] ?? null, null);

        PrinterProfile::create($data);

        return back()->with('success', 'Printer profile saved.');
    }

    public function update(Request $request, PrinterProfile $printerProfile): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $this->validated($request);
        $this->refuseADuplicate($data['warehouse_id'] ?? null, $printerProfile->id);

        $printerProfile->update($data);

        return back()->with('success', 'Printer profile updated.');
    }

    public function destroy(PrinterProfile $printerProfile): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $printerProfile->delete();

        // ⚠ Deleting is safe by construction: with no profile the counter falls
        // back to the business default, and with none of those to the built-in
        // 80mm. Nothing stops printing because a row went away.
        return back()->with('success', 'Printer profile removed. That counter now falls back to the business default.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'name' => ['required', 'string', 'max:100'],
            // ⚠ Bounded rather than free. A width outside this range is a typo,
            // and a typo here prints every receipt off the edge of the roll.
            'paper_width_mm' => ['required', 'integer', 'min:40', 'max:120'],
            'print_width_mm' => ['required', 'integer', 'min:30', 'max:120', 'lte:paper_width_mm'],
            'dpi' => ['required', 'integer', 'min:100', 'max:600'],
            'base_font_pt' => ['required', 'numeric', 'min:5', 'max:16'],
            'line_height' => ['required', 'numeric', 'min:1', 'max:2'],
            'show_logo' => ['required', 'boolean'],
            'show_barcode' => ['required', 'boolean'],
            'footer_note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
        ], [
            'print_width_mm.lte' => 'The printable width cannot be wider than the paper. An 80mm roll usually prints 72mm.',
        ]);
    }

    /**
     * ⚠⚠ THE RULE MYSQL CANNOT KEEP. At most one profile per business may have
     * no warehouse, because that row is the default every counter falls back to
     * and two of them is an unanswerable question. A UNIQUE index does not
     * enforce it: SQL treats each NULL as distinct, so both rows are accepted
     * and the resolver silently picks whichever comes back first.
     */
    private function refuseADuplicate(?int $warehouseId, ?int $ignoreId): void
    {
        $query = PrinterProfile::query()
            ->when($warehouseId === null,
                fn ($q) => $q->whereNull('warehouse_id'),
                fn ($q) => $q->where('warehouse_id', $warehouseId),
            )
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId));

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'warehouse_id' => $warehouseId === null
                ? 'This business already has a default printer profile. Edit that one, or attach this profile to an outlet.'
                : 'That outlet already has a printer profile. Edit the existing one rather than adding a second.',
        ]);
    }
}
