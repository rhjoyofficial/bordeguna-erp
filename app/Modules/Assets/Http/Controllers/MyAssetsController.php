<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Enums\MaintenanceKind;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My assets: what the signed-in employee holds, and "yes, I have it"
 * (slice A2). Self-scope like My profile: no permission, only one's own record.
 *
 * ⚠ Acknowledging is the ONLY thing an employee writes here. It never moves a
 * thing; a return is recorded by the keeper.
 */
class MyAssetsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')->with('error', 'Your account is not linked to an employee record.');
        }

        $items = AssetItem::query()->withoutGlobalScopes()
            ->with(['category:id,name', 'business:id,name'])
            ->where('custodian_employee_id', $employee->id)
            ->orderBy('tag')->get();

        // Slice A4: a problem already reported on each, and where it stands.
        $reports = AssetMaintenanceRecord::query()->withoutGlobalScopes()
            ->whereIn('asset_item_id', $items->pluck('id'))
            ->where('kind', MaintenanceKind::Repair->value)->where('state', MaintenanceState::Open->value)
            ->get(['asset_item_id', 'number', 'reported_on'])->keyBy('asset_item_id');

        $items = $items
            ->map(fn (AssetItem $item) => [
                'id' => $item->id,
                'tag' => $item->tag,
                'name' => $item->name,
                'category' => $item->category?->name,
                'business' => $item->business?->name,
                'since' => $item->custody_since?->toDateString(),
                'condition' => $item->condition->label(),
                'acknowledged_at' => $item->acknowledged_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'open_report' => ($report = $reports->get($item->id))
                    ? ['number' => $report->number, 'reported_on' => $report->reported_on->toDateString()]
                    : null,
                // Slice 3 (13 Oct 2026): the holder may reveal the logins of what they hold.
                'credentials' => AssetCredentialController::cardFor($request, $item),
            ]);

        $pieces = AssetHolding::query()->withoutGlobalScopes()->with('item:id,tag,name')
            ->where('employee_id', $employee->id)->get()
            ->map(fn (AssetHolding $holding) => ['tag' => $holding->item?->tag, 'name' => $holding->item?->name, 'quantity' => $holding->quantity]);

        return Inertia::render('hrm/my-assets/index', ['items' => $items, 'pieces' => $pieces]);
    }

    public function acknowledge(Request $request, AssetItem $item): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        // ⚠ Only the holder says they have it, and it is said once.
        abort_unless($employee !== null && $item->custodian_employee_id === $employee->id, 404);

        if ($item->acknowledged_at === null) {
            $item->forceFill(['acknowledged_at' => now()])->save();
            activity('assets.items')->performedOn($item)->causedBy($request->user())->log('Acknowledged: "Yes, I have it"');
        }

        return back()->with('success', "Thanks. {$item->tag} is recorded as with you.");
    }

    /**
     * Something is wrong with a thing I hold (slice A4). It opens a repair
     * record for the keeper; it changes nothing on the thing itself.
     */
    public function report(Request $request, AssetItem $item, RecordMaintenance $maintenance, StoreAttachment $store): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        // ⚠ Only the holder, as with the acknowledgement.
        abort_unless($employee !== null && $item->custodian_employee_id === $employee->id, 404);

        $data = $request->validate([
            'problem' => ['required', 'string', 'max:'.RecordMaintenance::MAX_TEXT],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $record = $maintenance->report($item, $data['problem'], $request->user());

        foreach ($request->file('photos', []) as $file) {
            $store->handle($record, $file, AssetMaintenanceRecord::PHOTOS, $request->user());
        }

        return back()->with('success', "Reported as {$record->number}. The asset keeper will follow it up.");
    }
}
