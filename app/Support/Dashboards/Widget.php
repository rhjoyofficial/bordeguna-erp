<?php

namespace App\Support\Dashboards;

use App\Models\User;

/**
 * One dashboard widget: the generalised form of the HR tile contract
 * (App\Modules\Hrm\Dashboard\Contracts\DashboardWidget), which stays as it is
 * for /hrm/dashboard.
 *
 * A widget declares what it is, where it appears, who may see it, how fresh it
 * is and where it drills down to, and returns a typed payload. It renders
 * nothing. ChatGPT ruling 2: the registry is not the auth system, so each widget
 * answers `authorize()` itself, on the server, and the registry applies it
 * before any data is computed.
 *
 * Payload kinds (resources/js/components/dashboards/types.ts mirrors them):
 *   stat       value + unit, optional previous, parts and sparkline
 *   series     a chart over time (area / line / bar)
 *   breakdown  a few categories (bar / donut)
 *   list       rows with columns, each row may link somewhere
 *   signals    business health signals
 *   progress   value against a target
 *   lookup     a searchable list
 * Every payload may also carry `watch`: named counts the browser compares
 * between refreshes to decide whether something happened (sound).
 *
 * ⚠ Money leaves as taka (float, 2 dp) computed from paisa, never a formatted
 * string, so the browser can count it up and chart it. `basis` says whether a
 * figure is posted ledger data or operational.
 */
abstract class Widget
{
    abstract public function key(): string;

    abstract public function title(): string;

    /** The grouping on the page: Money, Sales, Stock, People, Work. */
    abstract public function category(): string;

    /** @return list<DashboardKey> the dashboards this widget appears on */
    abstract public function dashboards(): array;

    /** @return array<string, mixed> the typed payload */
    abstract public function data(WidgetContext $context): array;

    /** One line saying what the figure means, and where it matters what it does not. */
    public function description(): ?string
    {
        return null;
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Business;
    }

    /** The permission the widget needs, or null for a personal widget anybody may have. */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * Whether this user may see the widget. Almost always the permission; a
     * widget may narrow further, never widen.
     */
    public function authorize(User $user): bool
    {
        return $this->permission() === null || $user->can($this->permission());
    }

    public function defaultVisible(): bool
    {
        return true;
    }

    /** How often the browser re-reads it while the socket is down (ruling 4). */
    public function refreshSeconds(): int
    {
        return 60;
    }

    /** How long the server may reuse a computed payload. 0 = always live. */
    public function cacheSeconds(): int
    {
        return 30;
    }

    /**
     * Which date the range filters on ("journal date", "order date"), or null
     * when the widget is a position as at now and ignores the range.
     */
    public function dateBasis(): ?string
    {
        return null;
    }

    /**
     * Whether the figure follows the dashboard's Brand switcher (Joy, 11 Oct
     * 2026). Orders, parcels and what was sold do; the ledger, people and stock
     * are the whole business, and their card says "All brands" instead.
     */
    public function brandAware(): bool
    {
        return false;
    }

    /** The existing screen that explains the figure. */
    public function drillDown(WidgetContext $context): ?string
    {
        return null;
    }

    public function emptyText(): string
    {
        return 'Nothing to show yet.';
    }

    /**
     * Whether the card's money figures are private (Joy, 14 Oct 2026: "on my
     * dashboard many private and important data shows"). A sensitive card
     * shows أمان in place of its money and percent figures until the person
     * taps its eye; counts of tasks, approvals and parcels stay readable.
     * Every widget that reports money says true.
     *
     * ⚠ Presentation only: the figure is still sent to a person who may see
     * it. The mask is against a glance over the shoulder, not an access rule.
     */
    public function sensitive(): bool
    {
        return false;
    }

    /** sm = one column, md = two, lg = the full row. */
    public function size(): string
    {
        return 'sm';
    }

    /**
     * Domain event types (App\Events\Dashboards\*) that should refresh this
     * widget at once when they arrive over the socket.
     *
     * @return list<string>
     */
    public function refreshOn(): array
    {
        return [];
    }

    /**
     * Rules for the browser: when a watched count rises, play this class of
     * sound and say this. [{watch, sound: success|attention|error, message}]
     *
     * @return list<array{watch: string, sound: string, message: string}>
     */
    public function notifications(): array
    {
        return [];
    }

    /** @return array<string, mixed> what the page needs to lay the widget out */
    public function meta(WidgetContext $context): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'description' => $this->description(),
            'category' => $this->category(),
            'scope' => $this->scope()->value,
            'size' => $this->size(),
            'sensitive' => $this->sensitive(),
            'refresh_seconds' => $this->refreshSeconds(),
            'cache_seconds' => $this->cacheSeconds(),
            'date_basis' => $this->dateBasis(),
            'brand_aware' => $this->brandAware(),
            'drill_down' => $this->drillDown($context),
            'empty_text' => $this->emptyText(),
            'refresh_on' => $this->refreshOn(),
            'notifications' => $this->notifications(),
        ];
    }

    // ─── Helpers the widgets share ─────────────────────────────────────────

    protected static function taka(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
