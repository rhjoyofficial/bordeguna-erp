<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Designation extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['designation_id'];
    }

    public function binLabel(): string
    {
        return 'Designation '.$this->name;
    }
}
