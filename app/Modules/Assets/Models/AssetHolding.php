<?php

namespace App\Modules\Assets\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many pieces of a counted group one holder has (slice A2). The tagged
 * things keep their custody on the item row; a pool of scissors is split
 * between a drawer and whoever took a pair.
 *
 * ⚠ DERIVED from the movements, kept in step in their transaction, and
 * rebuilt by `assets:rebuild-holdings` to prove it.
 */
class AssetHolding extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'asset_item_id', 'office_id', 'warehouse_id', 'employee_id', 'party_id', 'holder_key', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'party_id');
    }

    /**
     * The holder as one key: "o12", "w3", "e5" or "p2".
     */
    public static function keyFor(?int $officeId, ?int $warehouseId, ?int $employeeId, ?int $partyId): string
    {
        return match (true) {
            $employeeId !== null => "e{$employeeId}",
            $partyId !== null => "p{$partyId}",
            $officeId !== null => "o{$officeId}",
            $warehouseId !== null => "w{$warehouseId}",
            default => throw new \InvalidArgumentException('A holding needs a holder.'),
        };
    }

    public function holderLabel(): string
    {
        return $this->employee?->fullName()
            ?? $this->party?->label()
            ?? $this->office?->name
            ?? $this->warehouse?->name
            ?? $this->holder_key;
    }
}
