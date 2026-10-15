<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Support\InvoicedByChannel;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\NeedsAttention;
use App\Support\Dashboards\DashboardBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Sales overview (module redesign, 15 October 2026): today's numbers,
 * where every sale stands, what needs somebody, parcels by the courier's
 * word, money owed, orders by state, top products, statements to post and
 * the courier connections, on one page, every number a link to the list
 * behind it.
 *
 * ⚠⚠ EVERY FIGURE IS A DASHBOARD WIDGET'S OWN PAYLOAD. The page reads the
 * role dashboards' widgets through the same registry (`WidgetRegistry`), so
 * each number is the one the Owner, Manager and Sales dashboards already
 * show, computed once and cached the same way. A widget the person may not
 * see is never computed and arrives as null, not as zeros: a cashier with
 * `sales.sell` alone gets the till and the flow of nothing else, and the page
 * renders with any subset. The one figure no widget has, "invoiced today" by
 * channel and brand, is InvoicedByChannel: the Sales by channel report's own
 * arithmetic for today.
 *
 * ⚠ Needs attention is the same NeedsAttention the sidebar badge and the
 * /attention page read, narrowed to the courier and settlement sources and
 * grouped by reason; a source that fails is named, never a silent zero.
 */
class SalesOverviewController extends Controller
{
    /** The attention sources this page groups, by the reason each writes. */
    private const ATTENTION_SOURCES = [
        'courier_booking_unknown', 'courier_parcels_stuck', 'courier_door_decisions',
        'courier_loss_claims', 'courier_fee_variance', 'courier_settlement_overdue',
    ];

    private const ATTENTION_TITLES = [
        'booking_unknown' => 'Booking answer lost',
        'parcel_problem' => 'Courier reports a problem',
        'parcel_stuck' => 'Stuck in one courier status',
        'door_difference' => 'Door difference to decide',
        'loss_claim_open' => 'Loss claim waiting for the courier',
        'loss_claim_unpaid' => 'Compensation not arrived',
        'fee_variance' => 'Courier fee differs from the estimate',
        'cod_overdue' => 'Courier payout overdue',
    ];

    public function __construct(
        private readonly WidgetRegistry $widgets,
        private readonly NeedsAttention $attention,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $businessId = (int) app(BusinessContext::class)->id();
        $rangeError = null;

        try {
            $range = DateRange::fromQuery($request->query());
        } catch (ValidationException $exception) {
            $range = DateRange::fromQuery([]);
            $rangeError = collect($exception->errors())->flatten()->first();
        }

        $brandId = DashboardBrand::fromQuery($request->query('brand'), $businessId);
        // The Sales dashboard's key: the widgets read nothing from it but the
        // cache fragment, and that dashboard is where these cards come from.
        $context = new WidgetContext($user, $businessId, $range, DashboardKey::Sales, $brandId);
        $today = new WidgetContext($user, $businessId, DateRange::fromQuery(['range' => 'today']), DashboardKey::Sales, $brandId);

        $can = [
            'sales' => $user->can('sales.reports.view'),
            'sell' => $user->can('sales.sell'),
            'orders' => $user->can('sales.orders.record'),
            'parcels' => $user->can('sales.parcels.see'),
            'book' => $user->can('sales.sell') && $user->can('couriers.parcels.book'),
            'books' => $user->can('accounting.view'),
            'receivables' => $user->can('accounting.operations.view'),
            'courier_settings' => $user->can('couriers.settings.manage') || $user->can('couriers.ratecards.propose'),
        ];

        $ordersByState = $this->widget('orders_by_state', $context);
        $parcelsToday = $this->widget('courier_parcels_by_status', $today);
        $codChain = $this->widget('courier_cod_chain', $context);
        $invoiced = $can['sales'] ? app(InvoicedByChannel::class, ['businessId' => $businessId]) : null;

        return Inertia::render('sales/overview', [
            'today' => Carbon::today()->toDateString(),
            'range' => $range->toArray() + ['query' => $range->toQuery() + ($brandId === null ? [] : ['brand' => (string) $brandId])],
            'range_presets' => collect(['today', 'last_7_days', 'this_month', 'last_month'])
                ->map(fn (string $value) => ['value' => $value, 'label' => DateRange::PRESETS[$value]])->values()->all(),
            'range_error' => $rangeError,
            'brand' => ['selected' => $brandId, 'options' => DashboardBrand::options($businessId)],
            'can' => $can,
            'today_strip' => [
                'invoiced' => $invoiced?->today($brandId),
                'orders_in' => $ordersByState['watch']['orders_today'] ?? null,
                'delivered' => $parcelsToday === null ? null : (int) ($parcelsToday['watch']['delivered'] ?? 0),
                'came_back' => $parcelsToday === null ? null : (self::itemValue($parcelsToday, 'Returned to us (range)') ?? 0),
                'received' => $this->receivedToday($today),
                'till' => $can['sell'] ? $this->till($businessId) : null,
            ],
            'flow' => $this->flow($ordersByState, $this->widget('orders_to_dispatch', $context), $codChain, $can),
            'invoiced' => $invoiced?->between($range->from, $range->to, $brandId),
            'income_trend' => $this->widget('income_trend', $context),
            'attention' => $this->attention($user, $businessId, $brandId),
            'parcels' => $this->widget('courier_parcels_by_status', $context),
            'money' => [
                'receivables' => $this->widget('receivables_ageing', $context),
                'in_transit' => $this->widget('money_in_transit', $context),
                'courier_balances' => $this->widget('courier_balances', $context),
            ],
            'orders' => $ordersByState,
            'top_products' => $this->widget('top_products', $context),
            'statements' => $this->widget('courier_statements_pending', $context),
            'connections' => $this->widget('courier_integration_health', $context),
        ]);
    }

    /**
     * One widget's payload for this person, or null when they may not see it.
     * A widget that throws arrives as its error state (ruling 9), never as zeros.
     *
     * @return array<string, mixed>|null
     */
    private function widget(string $key, WidgetContext $context): ?array
    {
        $widget = $this->widgets->find($key);

        if ($widget === null || ! $widget->authorize($context->user)) {
            return null;
        }

        $resolved = $this->widgets->resolve($widget, $context);

        return $resolved['status'] === 'ok'
            ? $resolved['data'] + ['drill_down' => $widget->drillDown($context)]
            : ['kind' => 'error', 'message' => $resolved['message']];
    }

    /**
     * Where every sale stands: five queues, each from the widget that owns it.
     *
     * @param  array<string, mixed>|null  $ordersByState
     * @param  array<string, mixed>|null  $toDispatch
     * @param  array<string, mixed>|null  $codChain
     * @param  array<string, bool>  $can
     * @return list<array<string, mixed>>|null
     */
    private function flow(?array $ordersByState, ?array $toDispatch, ?array $codChain, array $can): ?array
    {
        if ($ordersByState === null && $codChain === null) {
            return null;
        }

        $stages = [];

        if ($ordersByState !== null && ($ordersByState['kind'] ?? null) === 'breakdown') {
            $awaiting = self::itemValue($ordersByState, 'Awaiting approval') ?? 0;
            $sentBack = self::itemValue($ordersByState, 'Sent back') ?? 0;
            $stages[] = [
                'key' => 'approval',
                'label' => 'Waiting approval',
                'value' => $awaiting + $sentBack,
                'unit' => 'orders',
                'note' => $sentBack > 0 ? "{$sentBack} sent back to the taker" : null,
                'tone' => $awaiting + $sentBack > 0 ? 'warning' : null,
                'url' => '/sales/orders?queue=approval',
            ];
        }

        if ($toDispatch !== null && ($toDispatch['kind'] ?? null) === 'list') {
            $stages[] = [
                'key' => 'dispatch',
                'label' => 'Approved, to dispatch',
                'value' => (int) ($toDispatch['total_count'] ?? 0),
                'unit' => 'orders',
                'note' => null,
                'tone' => null,
                'url' => $can['book'] ? '/sales/parcels-to-book' : '/sales/orders?queue=dispatch',
            ];
        }

        if ($codChain !== null && ($codChain['kind'] ?? null) === 'breakdown') {
            $items = collect($codChain['items'])->keyBy('key');
            $money = fn (float $taka) => number_format($taka, 0);

            foreach ([
                ['awaiting', 'With the courier', 'parcels', null, 'COD to collect ', '/sales/consignments'],
                ['recorded', 'Delivered, payout awaited', 'parcels', null, 'with couriers ', '/sales/courier-statements'],
                ['overdue', 'Payout overdue', 'parcels', 'danger', 'not paid out ', '/sales/courier-statements'],
            ] as [$key, $label, $unit, $tone, $prefix, $url]) {
                $item = $items->get($key);

                if ($item === null) {
                    continue;
                }

                $stages[] = [
                    'key' => $key,
                    'label' => $label,
                    'value' => (int) ($item['count'] ?? 0),
                    'unit' => $unit,
                    'note' => $prefix.$money((float) $item['value']),
                    'tone' => $tone !== null && (int) ($item['count'] ?? 0) > 0 ? $tone : null,
                    'url' => $url,
                ];
            }
        }

        return $stages === [] ? null : $stages;
    }

    /**
     * Money that came in today through cash, bank and wallets: the "Money in
     * and out" widget for today (accounting.view), as the ledger says it.
     */
    private function receivedToday(WidgetContext $today): ?float
    {
        $payload = $this->widget('receipts_vs_payments', $today);

        if ($payload === null || ($payload['kind'] ?? null) !== 'series') {
            return null;
        }

        return round(array_sum(array_map(fn (array $point) => (float) ($point['in'] ?? 0), $payload['points'])), 2);
    }

    /**
     * The open till shifts, as the Cashier shifts screen lists them.
     *
     * @return list<array{id: int, till: string|null, opened_by: string|null, opened_at: string}>
     */
    private function till(int $businessId): array
    {
        return DB::table('pos_shifts')
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->orderBy('opened_at')
            ->get()
            ->map(fn (object $shift) => [
                'id' => (int) $shift->id,
                'till' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
                'opened_by' => DB::table('users')->where('id', $shift->opened_by)->value('name'),
                'opened_at' => Carbon::parse($shift->opened_at)->timezone(config('app.timezone'))->format('H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * What is waiting on somebody on the courier side, grouped by reason so
     * the card reads "3 deliveries failed" rather than three lines. The
     * count behind a line is the sum each item declares (parcels on a
     * settlement, outliers on the fee check) or one per item.
     *
     * @return array{total: int, rows: list<array<string, mixed>>, failed: list<string>}|null
     */
    private function attention(User $user, int $businessId, ?int $brandId): ?array
    {
        $mine = array_filter($this->attention->sourcesFor($user), fn ($source) => in_array($source->key(), self::ATTENTION_SOURCES, true));

        if ($mine === []) {
            return null;
        }

        $result = $this->attention->for($user, $businessId);
        $groups = [];

        foreach ($result['items'] as $item) {
            if (! in_array($item->source, self::ATTENTION_SOURCES, true) || ! $item->matchesBrand($brandId)) {
                continue;
            }

            $reason = (string) preg_replace('/^attention\.reason\./', '', $item->reasonKey);
            $groups[$reason] ??= [
                'key' => $reason,
                'title' => self::ATTENTION_TITLES[$reason] ?? ucfirst(str_replace('_', ' ', $reason)),
                'hint' => $item->reason,
                'count' => 0,
                'items' => 0,
                'tone' => match ($item->severity) {
                    AttentionItem::CRITICAL => 'danger',
                    AttentionItem::HIGH => 'warning',
                    default => 'info',
                },
                'url' => $item->url,
            ];
            $groups[$reason]['count'] += $item->count ?? 1;
            $groups[$reason]['items']++;
        }

        return [
            'total' => count($result['items']),
            'rows' => array_values($groups),
            'failed' => array_values(array_filter(array_map(
                fn (array $failed) => in_array($failed['key'], self::ATTENTION_SOURCES, true) ? $failed['area'] : null,
                $result['failed'],
            ))),
        ];
    }

    /** One breakdown item's value by its label, or null when the widget has no such item. */
    private static function itemValue(array $payload, string $label): ?int
    {
        foreach ($payload['items'] ?? [] as $item) {
            if (($item['label'] ?? null) === $label) {
                return (int) $item['value'];
            }
        }

        return null;
    }
}
