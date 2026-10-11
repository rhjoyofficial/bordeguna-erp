<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\AttentionSource;
use App\Support\Attention\NeedsAttention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** What the sources share: the permission check and the item builder. */
abstract class Source implements AttentionSource
{
    /** @return list<string> any one of these lets the person see the source; empty = personal */
    abstract protected function permissions(): array;

    public function authorize(User $user): bool
    {
        $permissions = $this->permissions();

        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|int|float>  $params
     * @param  array<string, mixed>  $extra  since, ownerRole, ownerUserId, count, brandId, actions
     */
    protected function item(string $key, string $severity, string $reasonKey, string $english, array $params, string $url, array $extra = []): AttentionItem
    {
        $since = $extra['since'] ?? null;

        return new AttentionItem(
            key: $this->key().':'.$key,
            source: $this->key(),
            area: $this->area(),
            severity: $severity,
            reason: AttentionItem::sentence($english, $params),
            reasonKey: 'attention.reason.'.$reasonKey,
            params: $params,
            url: $url,
            since: $since === null ? null : Carbon::parse($since),
            ownerRole: $extra['ownerRole'] ?? null,
            ownerUserId: $extra['ownerUserId'] ?? null,
            count: $extra['count'] ?? null,
            brandId: $extra['brandId'] ?? null,
            actions: $extra['actions'] ?? [],
        );
    }

    /** Taka with thousands separators, as every money figure in the ERP reads. Never translated. */
    protected static function money(float|int|string|null $taka): string
    {
        return number_format((float) ($taka ?? 0), 2);
    }

    /**
     * The brand of each parcel, through its challan's order lines, when orders
     * carry a brand (guarded: the column does not exist yet everywhere).
     *
     * @param  list<int>  $consignmentIds
     * @return array<int, int> consignment id => brand id
     */
    protected static function parcelBrands(array $consignmentIds): array
    {
        if ($consignmentIds === [] || ! NeedsAttention::brandsKnown()) {
            return [];
        }

        return DB::table('consignments')
            ->join('delivery_lines', 'delivery_lines.delivery_id', '=', 'consignments.delivery_id')
            ->join('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereIn('consignments.id', $consignmentIds)
            ->whereNotNull('sales_orders.brand_id')
            ->groupBy('consignments.id')
            ->selectRaw('consignments.id as id, MIN(sales_orders.brand_id) as brand_id')
            ->pluck('brand_id', 'id')
            ->map(fn ($brand) => (int) $brand)
            ->all();
    }
}
