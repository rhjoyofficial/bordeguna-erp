<?php

namespace App\Providers;

use App\Support\Validation\ArrayFieldName;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as LaravelValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ⚠ P10 (18 Oct 2026, practice run): array fields with no name of their
        // own read as "line 1 product", never "lines.0.product_id". Specific
        // sentences ("Line 1: choose a product.") live in lang/en/validation.php.
        Validator::resolver(function ($translator, array $data, array $rules, array $messages, array $attributes) {
            $validator = new LaravelValidator($translator, $data, $rules, $messages, $attributes);
            $validator->setImplicitAttributesFormatter(fn (string $attribute) => ArrayFieldName::forHumans($attribute));

            return $validator;
        });
    }
}
