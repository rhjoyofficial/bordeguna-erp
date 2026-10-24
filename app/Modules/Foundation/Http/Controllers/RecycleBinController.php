<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Recycle bin (FINAL-PLAN section 9, answer 1): the Owner sees it and
 * restores from it. There is no "Delete forever" and there never will be.
 *
 * ⚠ Owner only, checked twice: the permission at the route, and the Owner role
 * (or the super admin) here, so a tick in Settings > Roles opens nothing.
 */
class RecycleBinController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request, RecycleBin $bin): Response
    {
        $this->authorize('recycle_bin.manage');
        abort_unless(Owners::is($request->user()), 403);

        return Inertia::render('settings/recycle-bin', [
            'entries' => $bin->entries($request->user(), $request->string('module')->toString() ?: null)->all(),
            'modules' => array_keys($bin->models()),
            'module' => $request->string('module')->toString() ?: null,
            // ⭐ Corrections wave 3, B11 (24 Oct 2026): what is SWITCHED OFF
            // rather than deleted, with Reactivate beside the bin's Restore
            // (FINAL-PLAN 2: "Delete if never used, otherwise Deactivate").
            // Logins of this team today; Reactivate runs Settings > Team's own
            // route, with its guards and a reason.
            'switched_off' => Business::query()->findOrFail(app(BusinessContext::class)->id())
                ->users()->where('is_active', false)->orderBy('name')->get()
                ->map(fn (User $user) => ['type' => 'user', 'id' => $user->id, 'label' => "Login {$user->name} ({$user->email})"])
                ->values()->all(),
            'reasons' => Reason::query()->active()->forContext('restore')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function restore(Request $request, string $type, int $id, RecycleBin $bin): RedirectResponse
    {
        $this->authorize('recycle_bin.manage');
        abort_unless(Owners::is($request->user()), 403);

        $request->validate($this->actionReasonRules('restore'), $this->actionReasonMessages());
        $bin->restore($type, $id, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Restored.');
    }
}
