<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is each courier connection working? Per courier account this business uses:
 * active or not, the last API call that worked, the last one that failed (and
 * its scrubbed error), and the last webhook the courier sent us about one of
 * our parcels.
 *
 * ⚠ courier_api_calls never holds a secret (CourierApiCall), so its short error
 * is safe to show.
 */
class CourierHealthWidget extends CourierWidget
{
    public function key(): string
    {
        return 'courier_integration_health';
    }

    public function title(): string
    {
        return 'Courier connections';
    }

    public function description(): ?string
    {
        return 'Per courier account: switched on or not, the last call that worked, the last error, and the last update the courier pushed to us. As at now.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager, DashboardKey::Sales];
    }

    public function permission(): ?string
    {
        return 'sales.parcels.see';
    }

    public function size(): string
    {
        return 'md';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/settings/couriers';
    }

    public function data(WidgetContext $context): array
    {
        $links = CourierAccountBusiness::query()
            ->withoutGlobalScopes()
            ->with('account.provider')
            ->where('business_id', $context->businessId)
            ->get();

        if ($links->isEmpty()) {
            return ['kind' => 'empty', 'message' => 'No courier account is connected to this business.'];
        }

        $accountIds = $links->pluck('courier_account_id')->all();

        $lastOk = CourierApiCall::query()->whereIn('courier_account_id', $accountIds)->where('ok', true)
            ->groupBy('courier_account_id')->selectRaw('courier_account_id, MAX(created_at) as at')->pluck('at', 'courier_account_id');

        $lastErrors = CourierApiCall::query()->whereIn('courier_account_id', $accountIds)->where('ok', false)
            ->groupBy('courier_account_id')->selectRaw('courier_account_id, MAX(id) as id')->pluck('id');
        $errors = CourierApiCall::query()->whereIn('id', $lastErrors)->get()->keyBy('courier_account_id');

        $lastWebhook = DB::table('courier_shipment_events')
            ->join('consignments', 'consignments.id', '=', 'courier_shipment_events.consignment_id')
            ->where('courier_shipment_events.business_id', $context->businessId)
            ->where('courier_shipment_events.source', 'webhook')
            ->whereIn('consignments.courier_account_id', $accountIds)
            ->groupBy('consignments.courier_account_id')
            ->selectRaw('consignments.courier_account_id as account_id, MAX(courier_shipment_events.created_at) as at')
            ->pluck('at', 'account_id');

        $ago = fn ($at) => $at === null ? 'Never' : Carbon::parse($at)->diffForHumans();

        $rows = $links->map(function (CourierAccountBusiness $link) use ($lastOk, $errors, $lastWebhook, $ago) {
            $account = $link->account;
            $id = (int) $link->courier_account_id;
            $error = $errors->get($id);
            $okAt = $lastOk[$id] ?? null;
            $failing = $error !== null && ($okAt === null || Carbon::parse($error->created_at)->gt(Carbon::parse($okAt)));

            return [
                'id' => $id,
                'url' => '/settings/couriers',
                'tone' => ! $account?->is_active ? 'warn' : ($failing ? 'bad' : 'good'),
                'cells' => [
                    'account' => trim(($account?->provider?->name ?? '').' '.($account?->label ?? '')),
                    'state' => ! $account?->is_active ? 'Off' : ($failing ? 'Failing' : 'Working'),
                    'last_ok' => $ago($okAt),
                    'last_error' => $error === null ? 'None' : $ago($error->created_at).': '.mb_strimwidth((string) $error->error, 0, 80, '...'),
                    'last_webhook' => $ago($lastWebhook[$id] ?? null),
                ],
            ];
        })->values()->all();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'account', 'label' => 'Courier account'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'last_ok', 'label' => 'Last good call'],
                ['key' => 'last_error', 'label' => 'Last error'],
                ['key' => 'last_webhook', 'label' => 'Last update pushed'],
            ],
            'rows' => $rows,
            'total_count' => count($rows),
            'basis' => 'operational',
            'watch' => ['failing' => count(array_filter($rows, fn ($r) => $r['tone'] === 'bad'))],
        ];
    }
}
