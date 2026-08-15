<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit record of one applied `catalogue:migrate` run. See the migration
 * for why the mapping JSON is the whole reversal recipe.
 */
class CatalogueMigrationRun extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'filename',
        'file_hash',
        'renames_count',
        'retires_count',
        'creates_count',
        'keeps_count',
        'skips_count',
        'mapping',
    ];

    protected function casts(): array
    {
        return [
            'renames_count' => 'integer',
            'retires_count' => 'integer',
            'creates_count' => 'integer',
            'keeps_count' => 'integer',
            'skips_count' => 'integer',
            'mapping' => 'array',
        ];
    }
}
