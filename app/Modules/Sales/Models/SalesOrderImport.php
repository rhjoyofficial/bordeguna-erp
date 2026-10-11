<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shop order sheet, imported (Joy, 11 Oct 2026: shop orders reach the ERP
 * by hand or by import sheet until the website module is ready).
 *
 * ⚠ The original file stays on the private disk: it carries customer names and
 * phones, and it is the evidence of what was imported. Its SHA-256 is unique
 * per business, so the same file never imports twice.
 */
class SalesOrderImport extends Model
{
    use BelongsToBusiness;

    public const DISK = 'local';

    protected $fillable = [
        'business_id', 'file_name', 'file_path', 'file_sha256', 'file_size', 'rows',
        'orders_created', 'orders_skipped', 'orders_failed', 'auto_approve', 'money_mappings', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'auto_approve' => 'boolean',
            'money_mappings' => 'array',
            'rows' => 'integer',
            'file_size' => 'integer',
            'orders_created' => 'integer',
            'orders_skipped' => 'integer',
            'orders_failed' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrderImportOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
