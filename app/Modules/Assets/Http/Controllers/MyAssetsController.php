<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
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
            ->orderBy('tag')->get()
            ->map(fn (AssetItem $item) => [
                'id' => $item->id,
                'tag' => $item->tag,
                'name' => $item->name,
                'category' => $item->category?->name,
                'business' => $item->business?->name,
                'since' => $item->custody_since?->toDateString(),
                'condition' => $item->condition->label(),
                'acknowledged_at' => $item->acknowledged_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
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
}
