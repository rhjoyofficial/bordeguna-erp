<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'payment_terms',
        'bin',
        'tin',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_number',
        'mfs_number',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
