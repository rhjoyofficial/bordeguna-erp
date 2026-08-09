<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Crm\Models\CrmProductInterest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Records that somebody asked for something.
 *
 * ⚠ Every rule lives here rather than in the controller, because a second
 * caller is already in prospect - a Meta lead form carries "interested in" - and
 * a rule that lives in one screen is a rule the next caller does not have.
 */
class RecordProductInterest
{
    /**
     * @throws ValidationException
     */
    public function handle(
        Model $subject,
        User $recordedBy,
        ?Product $product = null,
        ?Category $category = null,
        ?Brand $brand = null,
        ?string $requestedQuantity = null,
        ?UnitOfMeasure $requestedUnit = null,
        ?string $note = null,
        mixed $requestedAt = null,
    ): CrmProductInterest {
        $businessId = app(BusinessContext::class)->id();

        if (! in_array($subject::class, CrmProductInterest::SUBJECTS, true)) {
            throw ValidationException::withMessages([
                'subject' => 'Interest can only be recorded against a lead or a customer.',
            ]);
        }

        if ($subject->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'subject' => 'That record belongs to a different business.',
            ]);
        }

        // ⚠⚠ EXACTLY ONE OF THE THREE. Zero means the row says nothing; more
        // than one means "wants honey, and specifically this jar, and anything
        // LaLaDia" - three different statements crammed into one, and every
        // count over the table would be ambiguous.
        $chosen = array_filter([
            'product_id' => $product,
            'category_id' => $category,
            'brand_id' => $brand,
        ]);

        if (count($chosen) !== 1) {
            throw ValidationException::withMessages([
                'product_id' => 'Say exactly one of: a product, a category, or a brand.',
            ]);
        }

        foreach ([$product, $category, $brand, $requestedUnit] as $related) {
            if ($related !== null && $related->business_id !== $businessId) {
                throw ValidationException::withMessages([
                    'product_id' => 'That belongs to a different business.',
                ]);
            }
        }

        $requested = $requestedAt === null ? now() : Carbon::parse($requestedAt);

        // ⚠ A request that has not been made yet is not a request. Same rule as
        // an activity's occurred_at, for the same reason.
        if ($requested->isFuture()) {
            throw ValidationException::withMessages([
                'requested_at' => 'That is in the future. Somebody cannot have asked for something yet.',
            ]);
        }

        if ($requestedQuantity !== null && (float) $requestedQuantity <= 0) {
            throw ValidationException::withMessages([
                'requested_quantity' => 'A requested quantity must be more than nothing. Leave it blank if they did not say.',
            ]);
        }

        // ⚠⚠ A UNIT WITHOUT A QUANTITY IS MEANINGLESS. "Kilograms" is not a
        // request; "5 kilograms" is. Storing the unit alone would put a figure
        // on a report with nothing behind it.
        if ($requestedUnit !== null && $requestedQuantity === null) {
            throw ValidationException::withMessages([
                'requested_quantity' => 'Say how many, or leave the unit blank.',
            ]);
        }

        return CrmProductInterest::create([
            'business_id' => $businessId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'product_id' => $product?->id,
            'category_id' => $category?->id,
            'brand_id' => $brand?->id,
            'requested_quantity' => $requestedQuantity,
            'requested_unit_id' => $requestedUnit?->id,
            'note' => $note,
            'requested_at' => $requested,
            'recorded_by' => $recordedBy->id,
            'status' => InterestStatus::Open,
        ]);
    }
}
