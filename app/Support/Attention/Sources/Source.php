<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\AttentionSource;
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
     * @param  array<string, mixed>  $extra  since, ownerRole, ownerUserId, count, brandId (present = a per-parcel item), actions
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
            brandAware: array_key_exists('brandId', $extra),
        );
    }

    /** Taka with thousands separators, as every money figure in the ERP reads. Never translated. */
    protected static function money(float|int|string|null $taka): string
    {
        return number_format((float) ($taka ?? 0), 2);
    }

    /**
     * The brand of each parcel, through its challan's order (OrderBrand, the
     * same path the parcels list filters by).
     *
     * @param  list<int>  $consignmentIds
     * @return array<int, int> consignment id => brand id (no entry = no brand)
     */
    protected static function parcelBrands(array $consignmentIds): array
    {
        if ($consignmentIds === []) {
            return [];
        }

        $deliveries = DB::table('consignments')->whereIn('id', $consignmentIds)->pluck('delivery_id', 'id');
        $brands = OrderBrand::forDeliveries($deliveries->values());

        return $deliveries
            ->mapWithKeys(fn ($deliveryId, $id) => [(int) $id => $brands[(int) $deliveryId] ?? null])
            ->filter(fn ($brand) => $brand !== null)
            ->all();
    }
}
