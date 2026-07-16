<?php

namespace App\Modules\Foundation\Concerns;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if ($model->business_id === null) {
                $model->business_id = app(BusinessContext::class)->id();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
