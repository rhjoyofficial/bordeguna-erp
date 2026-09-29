<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A dated TDS or VDS rate for a type of supplier (finance decision C4). The
 * owner keeps these himself; a rate that changes gets a new row from its date,
 * so an old payment still reads the rate it was made under.
 */
class WithholdingRate extends Model
{
    use BelongsToBusiness;

    public const KINDS = ['tds' => 'TDS (income tax at source)', 'vds' => 'VDS (VAT at source)'];

    protected $fillable = ['business_id', 'kind', 'supplier_type', 'name', 'rate', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function scopeInForceOn(Builder $query, mixed $date): Builder
    {
        $on = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $query->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on));
    }

    public function appliesTo(Supplier $supplier): bool
    {
        return $this->supplier_type === null || $this->supplier_type === $supplier->supplier_type;
    }

    /** The tax on a gross amount, rounded to the paisa. */
    public function taxOn(Money $gross): Money
    {
        return Money::ofMinor((int) round($gross->minor * (float) $this->rate / 100));
    }

    public function label(): string
    {
        return "{$this->name} ".rtrim(rtrim(number_format((float) $this->rate, 4, '.', ''), '0'), '.').'%';
    }
}
