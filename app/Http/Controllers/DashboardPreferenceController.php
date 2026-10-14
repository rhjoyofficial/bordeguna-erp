<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Models\DashboardLayout;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\GettingStarted;
use App\Support\Dashboards\SoundPreferences;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One person's own dashboard settings: hidden widgets per dashboard, and the
 * sound settings. JSON, because the page saves them in the
 * background without leaving the dashboard.
 *
 * ⚠ A layout is presentation. It can only hold keys of widgets the person is
 * authorised for on that dashboard; anything else is dropped, and hiding grants
 * or removes nothing.
 *
 * ⚠ 14 Oct 2026 (Joy: "by pressing ... i broke the design layout, reset it"):
 * a person may only hide and show. Order and size belong to the dashboard's
 * design; an `order`, `size` or `span` field in the body is ignored, never
 * stored, and the old stored order is wiped on the next save.
 */
class DashboardPreferenceController extends Controller
{
    public function layout(Request $request, string $dashboard, DashboardAccess $access, WidgetRegistry $registry, BusinessContext $context): JsonResponse
    {
        $key = DashboardKey::tryFrom($dashboard);
        abort_if($key === null, 404);
        abort_unless($access->canOpen($request->user(), $key), 403);

        $allowed = $registry->forDashboard($key, $request->user())->keys()->all();

        // Only `hidden` is read; anything else in the body (order, size, span) is dropped unread.
        $data = $request->validate([
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string', Rule::in($allowed)],
        ]);

        DashboardLayout::query()->updateOrCreate(
            ['business_id' => $context->id(), 'user_id' => $request->user()->id, 'dashboard' => $key->value],
            ['widget_order' => null, 'hidden' => array_values(array_unique($data['hidden']))],
        );

        return response()->json(['saved' => true]);
    }

    /**
     * "Reset to default": forget this person's layout on this dashboard, so the
     * designed order, sizes and default-visible set apply again. Nobody else is
     * affected.
     */
    public function resetLayout(Request $request, string $dashboard, DashboardAccess $access, BusinessContext $context): JsonResponse
    {
        $key = DashboardKey::tryFrom($dashboard);
        abort_if($key === null, 404);
        abort_unless($access->canOpen($request->user(), $key), 403);

        DashboardLayout::query()
            ->where('business_id', $context->id())
            ->where('user_id', $request->user()->id)
            ->where('dashboard', $key->value)
            ->delete();

        return response()->json(['saved' => true]);
    }

    public function sound(Request $request, DashboardAccess $access): JsonResponse
    {
        SoundPreferences::save($request->user(), $request->validate(SoundPreferences::rules()));

        return response()->json([
            'saved' => true,
            'sound' => SoundPreferences::for($request->user()->fresh(), $access->canViewAll($request->user())),
        ]);
    }

    /** Hide the Getting started card for this person in this business. Nobody else is affected. */
    public function dismissGettingStarted(Request $request, BusinessContext $context): JsonResponse
    {
        GettingStarted::dismiss($request->user(), (int) $context->id());

        return response()->json(['saved' => true]);
    }
}
