<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A description of the paper a counter prints on.
 *
 * ⚠ A row with no warehouse is the business default. There must be at most one
 * of those, and MySQL cannot enforce it: a UNIQUE index treats every NULL as
 * distinct, so two default rows would both be accepted. The rule is therefore
 * kept by PrinterProfileController and pinned by a test, and this comment
 * exists so nobody adds the index later believing it does something.
 */
class PrinterProfile extends Model
{
    use BelongsToBusiness;
    use LogsActivity;

    protected $fillable = [
        'warehouse_id',
        'name',
        'paper_width_mm',
        'print_width_mm',
        'dpi',
        'base_font_pt',
        'line_height',
        'show_logo',
        'show_barcode',
        'footer_note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'paper_width_mm' => 'integer',
            'print_width_mm' => 'integer',
            'dpi' => 'integer',
            'base_font_pt' => 'float',
            'line_height' => 'float',
            'show_logo' => 'boolean',
            'show_barcode' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
