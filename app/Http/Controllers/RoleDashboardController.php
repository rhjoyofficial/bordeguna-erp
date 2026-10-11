<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DashboardPage;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\SoundPreferences;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role dashboards (Joy, 11 Oct 2026): every person lands on the dashboard
 * of their highest role in the current business; Joy, holding
 * dashboard.view_all, may open every one of them.
 *
 * ⚠ The switcher is a convenience, never the gate: /dashboard/{key} checks
 * DashboardAccess on every request, and the widget endpoint checks again.
 */
class RoleDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $access,
        private readonly DashboardPage $page,
        private readonly BusinessContext $context,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request, $this->access->landingKey($request->user()));
    }

    public function show(Request $request, string $dashboard): Response
    {
        $key = DashboardKey::tryFrom($dashboard);

        abort_if($key === null, 404);
        abort_unless($this->access->canOpen($request->user(), $key), 403);

        if ($this->access->reachedThroughViewAll($request->user(), $key)) {
            // ⚠ Ruling 1: the cross-dashboard permission is AUDITED. Only a view
            // the person's own roles would not give them is written down.
            activity('dashboard')
                ->causedBy($request->user())
                ->withProperties(['dashboard' => $key->value, 'via' => DashboardAccess::VIEW_ALL])
                ->log("Opened the {$key->label()} dashboard through see-all access");
        }

        return $this->render($request, $key);
    }

    private function render(Request $request, DashboardKey $key): Response
    {
        $user = $request->user();
        $businessId = (int) $this->context->id();
        $rangeError = null;

        try {
            $range = DateRange::fromQuery($request->query());
        } catch (ValidationException $exception) {
            $range = DateRange::fromQuery([]);
            $rangeError = collect($exception->errors())->flatten()->first();
        }

        $context = new WidgetContext($user, $businessId, $range, $key);
        ['visible' => $visible, 'hidden' => $hidden] = $this->page->arrange($key, $user, $businessId);
        $landing = $this->access->landingKey($user);
        $viewAll = $this->access->canViewAll($user);

        return Inertia::render('dashboards/show', [
            'dashboard' => ['key' => $key->value, 'label' => $key->label(), 'description' => $key->description()],
            'switcher' => collect($this->access->allowedKeys($user))->map(fn (DashboardKey $option) => [
                'key' => $option->value,
                'label' => $option->label(),
                'url' => $option === $landing ? '/dashboard' : "/dashboard/{$option->value}",
                'current' => $option === $key,
            ])->values()->all(),
            'offers_classic' => $this->access->offersClassic($user),
            'widgets' => $this->page->payload($visible, $context),
            'hidden_widgets' => $hidden->map(fn (Widget $w) => ['key' => $w->key(), 'title' => $w->title()])->values()->all(),
            'range' => $range->toArray() + ['query' => $range->toQuery()],
            'range_presets' => collect(DateRange::PRESETS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'range_error' => $rangeError,
            'sound' => SoundPreferences::for($user, $viewAll),
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb',
                'business_channel' => "business.{$businessId}",
                'user_channel' => "user.{$user->id}",
            ],
            'can_configure' => $viewAll,
            // The page refreshes itself widget by widget (socket first, polling as
            // the fallback); the global 30 s Inertia reload stands down here.
            'auto_refresh' => false,
        ]);
    }
}
