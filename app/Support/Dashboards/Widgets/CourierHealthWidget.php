<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Sales\Couriers\Models\CourierAccount;
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
        return 'Per courier account: switched on or not, the last call that worked, the last error, whether its webhook is paired, and the last update the courier pushed to us. As at now.';
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

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $links = CourierAccountBusiness::query()
            ->withoutGlobalScopes()
            ->with('account.provider')
            ->where('business_id', $context->businessId)
            // ⭐ With a brand chosen: the accounts that carry its parcels (its
            // own links and the any-brand ones). One row per account either way.
            ->when($context->brandId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('brand_id')->orWhere('brand_id', $context->brandId)))
            ->orderBy('id')
            ->get()
            ->unique('courier_account_id')
            ->values();

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

            // The newest authentic update: stamped on the account by the webhook
            // (pairing guard), or the newest webhook event on one of our parcels.
            $pushed = collect([$account?->webhook_last_event_at, $lastWebhook[$id] ?? null])
                ->filter()
                ->map(fn ($at) => Carbon::parse($at))
                ->sortDesc()
                ->first();
            [$webhook, $webhookTone] = self::webhookState($account);

            return [
                'id' => $id,
                'url' => '/settings/couriers',
                'tone' => ! $account?->is_active ? 'warn' : ($failing ? 'bad' : 'good'),
                // One cell may speak louder than its row: a missing reply value is red.
                'cell_tones' => array_filter(['webhook' => $webhookTone]),
                'cells' => [
                    // Named as Settings > Couriers names it: "Pathao: <label>".
                    'account' => implode(': ', array_filter([$account?->provider?->name, $account?->label])),
                    'state' => ! $account?->is_active ? 'Off' : ($failing ? 'Failing' : 'Working'),
                    'last_ok' => $ago($okAt),
                    'last_error' => $error === null ? 'None' : $ago($error->created_at).': '.mb_strimwidth((string) $error->error, 0, 80, '...'),
                    'webhook' => $webhook,
                    'last_webhook' => $ago($pushed),
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
                ['key' => 'webhook', 'label' => 'Webhook'],
                ['key' => 'last_webhook', 'label' => 'Last update pushed'],
            ],
            'rows' => $rows,
            'total_count' => count($rows),
            'basis' => 'operational',
            'watch' => ['failing' => count(array_filter($rows, fn ($r) => $r['tone'] === 'bad'))],
        ];
    }

    /**
     * Whether the courier's webhook is paired with us, from what the pairing
     * guard records on the account: paired (with the day), the courier's
     * reply value missing, the secret not matching, or never tried. An
     * authentic update after a failed attempt means it paired after all.
     *
     * @return array{0: string, 1: 'bad'|null}
     */
    private static function webhookState(?CourierAccount $account): array
    {
        $tried = $account?->webhook_last_handshake_at;
        $heard = $account?->webhook_last_event_at;
        $day = fn (Carbon $at) => $at->copy()->timezone('Asia/Dhaka')->format('j M');

        if ($account === null || ($tried === null && $heard === null)) {
            return ['Never tried', null];
        }

        if ($account->webhook_last_handshake_ok === false && ($heard === null || $heard->lt($tried))) {
            return $account->webhook_last_handshake_problem === CourierAccount::PAIRING_BAD_SIGNATURE
                ? ['Secret does not match', 'bad']
                : ['Reply value missing', 'bad'];
        }

        return ['Paired '.$day($account->webhook_last_handshake_ok ? $tried : ($heard ?? $tried)), null];
    }
}
