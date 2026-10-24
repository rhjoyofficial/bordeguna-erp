<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseBin;
use App\Modules\Purchasing\Models\SupplierType;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): the master lists whose rows carry
 * Delete (to the Recycle bin, never used), Switch off (used, with a reason)
 * and Reactivate, through ONE set of routes (`/masters/{type}/{id}/...`).
 *
 * Each entry: the model (RecyclableMaster + Removable), the permission that
 * already edits that list, and whether the list has an on/off switch.
 * ⚠ The key is the Recycle bin's type key too (config/recycle_bin.php).
 */
final class MasterRecords
{
    /** @return array<string, array{model: class-string<Model>, permission: string, switch: bool}> */
    public static function types(): array
    {
        return [
            'product' => ['model' => Product::class, 'permission' => 'inventory.catalog.manage', 'switch' => true],
            'category' => ['model' => Category::class, 'permission' => 'inventory.catalog.manage', 'switch' => false],
            'brand' => ['model' => Brand::class, 'permission' => 'inventory.catalog.manage', 'switch' => true],
            'unit' => ['model' => UnitOfMeasure::class, 'permission' => 'inventory.catalog.manage', 'switch' => false],
            'warehouse' => ['model' => Warehouse::class, 'permission' => 'inventory.catalog.manage', 'switch' => true],
            'bin' => ['model' => WarehouseBin::class, 'permission' => 'inventory.catalog.manage', 'switch' => true],
            'price_group' => ['model' => PriceGroup::class, 'permission' => 'sales.prices.manage', 'switch' => true],
            'money_account' => ['model' => MoneyAccount::class, 'permission' => 'accounting.money_account.manage', 'switch' => true],
            'supplier_type' => ['model' => SupplierType::class, 'permission' => 'purchasing.suppliers.manage', 'switch' => true],
        ];
    }

    /** @return array{model: class-string<Model>, permission: string, switch: bool} */
    public static function type(string $type): array
    {
        return self::types()[$type] ?? throw new NotFoundHttpException;
    }

    /**
     * What each row of a list may do, for a whole page in one pass (one
     * grouped query per pointer, never one scan per row).
     *
     * @param  iterable<Model>  $records
     * @return array<int, array{type: string, id: int, is_active: ?bool, may: bool, delete_blocked: ?string, switch_blocked: ?string}>
     */
    public static function rows(string $type, iterable $records, ?User $user): array
    {
        $spec = self::type($type);
        $records = collect($records);
        $may = $user !== null && $user->can($spec['permission']);
        $out = [];

        if ($records->isEmpty()) {
            return $out;
        }

        /** @var Model&Removable $first */
        $first = $records->first();
        $usage = $may ? ReferenceScan::usageMany($first, $records->map(fn (Model $m) => (int) $m->getKey())->all(), $first->usageColumns(), $first->usageIgnoredTables()) : [];

        foreach ($records as $record) {
            $id = (int) $record->getKey();
            $deleteBlocked = $may
                ? ($record->whyKept() ?? $record::usageSentence($record->binLabel(), $usage[$id] ?? []))
                : 'You may not change this list.';

            $out[$id] = [
                'type' => $type,
                'id' => $id,
                'is_active' => $spec['switch'] ? (bool) $record->is_active : null,
                'may' => $may,
                'delete_blocked' => $deleteBlocked,
                'switch_blocked' => $spec['switch'] && $may ? $record->whyNotDeactivatable() : null,
            ];
        }

        return $out;
    }

    /** The reasons the Delete and Switch off dialogs offer. */
    public static function reasons(): array
    {
        return CorrectionScreen::reasons('deletion');
    }
}
