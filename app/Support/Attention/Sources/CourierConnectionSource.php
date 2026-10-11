<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is each switched-on courier account still talking to us? Per account this
 * business uses:
 *
 *   failing        the newest API call failed and nothing has worked since (critical)
 *   stale          parcels are open and no call has worked for SYNC_STALE_HOURS (high)
 *   webhook quiet  parcels are open and the courier has pushed nothing for WEBHOOK_SILENT_HOURS (high)
 *
 * ⚠ courier_api_calls never holds a secret (CourierApiCall), so its short
 * error is safe to quote. A failing account is not also called stale: one
 * problem, one line.
 */
class CourierConnectionSource extends Source
{
    public const SYNC_STALE_HOURS = 2;

    public const WEBHOOK_SILENT_HOURS = 24;

    public function key(): string
    {
        return 'courier_connections';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['sales.parcels.see'];
    }

    public function items(User $user, int $businessId): array
    {
        $links = CourierAccountBusiness::query()
            ->withoutGlobalScopes()
            ->with('account.provider')
            ->where('business_id', $businessId)
            ->get()
            ->filter(fn (CourierAccountBusiness $link) => (bool) $link->account?->is_active);

        if ($links->isEmpty()) {
            return [];
        }

        $ids = $links->pluck('courier_account_id')->map(fn ($id) => (int) $id)->all();

        $lastOk = CourierApiCall::query()->whereIn('courier_account_id', $ids)->where('ok', true)
            ->groupBy('courier_account_id')->selectRaw('courier_account_id, MAX(created_at) as at')->pluck('at', 'courier_account_id');
        $lastErrorIds = CourierApiCall::query()->whereIn('courier_account_id', $ids)->where('ok', false)
            ->groupBy('courier_account_id')->selectRaw('MAX(id) as id')->pluck('id');
        $errors = CourierApiCall::query()->whereIn('id', $lastErrorIds)->get()->keyBy('courier_account_id');

        $open = Consignment::query()
            ->where('business_id', $businessId)
            ->whereIn('courier_account_id', $ids)
            ->where('status', ConsignmentStatus::Booked->value)
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', CourierStatus::finalValues()))
            ->groupBy('courier_account_id')
            ->selectRaw('courier_account_id, COUNT(*) as parcels')
            ->pluck('parcels', 'courier_account_id');

        $lastWebhook = DB::table('courier_shipment_events')
            ->join('consignments', 'consignments.id', '=', 'courier_shipment_events.consignment_id')
            ->where('courier_shipment_events.business_id', $businessId)
            ->where('courier_shipment_events.source', 'webhook')
            ->whereIn('consignments.courier_account_id', $ids)
            ->groupBy('consignments.courier_account_id')
            ->selectRaw('consignments.courier_account_id as account_id, MAX(courier_shipment_events.created_at) as at')
            ->pluck('at', 'account_id');

        $items = [];
        $now = Carbon::now();

        foreach ($links as $link) {
            $account = $link->account;
            $id = (int) $link->courier_account_id;
            $name = trim(($account->provider?->name ?? '').' '.($account->label ?? ''));
            $okAt = isset($lastOk[$id]) ? Carbon::parse($lastOk[$id]) : null;
            $error = $errors->get($id);
            $parcels = (int) ($open[$id] ?? 0);

            if ($error !== null && ($okAt === null || Carbon::parse($error->created_at)->gt($okAt))) {
                $items[] = $this->item(
                    "{$id}:failing",
                    AttentionItem::CRITICAL,
                    'courier_failing',
                    '{account}: calls to the courier are failing. Last error: {error}',
                    ['account' => $name, 'error' => mb_strimwidth((string) $error->error, 0, 120, '...')],
                    '/settings/couriers',
                    ['since' => $error->created_at, 'ownerRole' => 'Owner'],
                );

                continue;
            }

            if ($parcels === 0) {
                continue;
            }

            if ($okAt === null || $okAt->lt($now->copy()->subHours(self::SYNC_STALE_HOURS))) {
                $items[] = $this->item(
                    "{$id}:stale",
                    AttentionItem::HIGH,
                    'courier_stale',
                    '{account}: {count} parcels are open and nothing has been read from the courier for over {hours} hours.',
                    ['account' => $name, 'count' => $parcels, 'hours' => self::SYNC_STALE_HOURS],
                    '/settings/couriers',
                    ['since' => $okAt ?? $account->activated_at, 'ownerRole' => 'Owner', 'count' => $parcels],
                );
            }

            $webhookAt = isset($lastWebhook[$id]) ? Carbon::parse($lastWebhook[$id]) : null;
            $quietSince = $webhookAt ?? $account->activated_at;

            if ($quietSince !== null && Carbon::parse($quietSince)->lt($now->copy()->subHours(self::WEBHOOK_SILENT_HOURS))) {
                $items[] = $this->item(
                    "{$id}:webhook",
                    AttentionItem::HIGH,
                    'courier_webhook_silent',
                    '{account}: the courier has pushed no update for over {hours} hours while {count} parcels are open.',
                    ['account' => $name, 'count' => $parcels, 'hours' => self::WEBHOOK_SILENT_HOURS],
                    '/settings/couriers',
                    ['since' => $quietSince, 'ownerRole' => 'Owner', 'count' => $parcels],
                );
            }
        }

        return $items;
    }
}
