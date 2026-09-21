<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventorySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approval limit for stock corrections, on a screen at last.
 *
 * ⚠⚠ `inventory_settings.adjustment_approval_threshold` has existed since
 * 2 Aug 2026 with no screen and no seeder, so it was NULL everywhere - and NULL
 * means every correction waits in /approvals, the Owner's own included. That
 * is the safe default and it stays the default: this screen never fills in an
 * amount by itself, because a limit on who may destroy inventory value is the
 * owner's number, not a developer's guess (see the migration's own warning).
 *
 * ⚠ Gated on `inventory.stock.approve`. Deciding which corrections need an
 * approver is part of being the approver; the permission is not new and
 * nobody gains it here.
 */
class InventorySettingController extends Controller
{
    public function edit(): Response
    {
        // ⚠ Read, never created. A GET that writes the settings row would make
        // "nobody has ever set this" indistinguishable from "somebody saved it".
        $setting = InventorySetting::query()->first();

        return Inertia::render('inventory/settings/index', [
            'adjustment_approval_threshold' => $setting?->adjustment_approval_threshold,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // decimal(15,4): eleven digits before the point.
            'adjustment_approval_threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ], [
            'adjustment_approval_threshold.numeric' => 'Enter an amount in taka, or leave it empty.',
        ]);

        $threshold = $data['adjustment_approval_threshold'] ?? null;

        InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => $threshold]);

        return back()->with('success', $threshold === null
            ? 'Approval limit cleared. Every stock correction waits for approval.'
            : 'Approval limit saved.');
    }
}
