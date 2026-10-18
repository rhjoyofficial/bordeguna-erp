<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Proprietor;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Writes or rewrites a DRAFT owner withdrawal with its lines (Phase 2, 17 Oct
 * 2026). Nothing here touches stock or the ledger: authorisation reserves
 * the batches, posting moves them.
 *
 * The proprietor is NOT chosen on the form: he is whoever Settings > Company
 * names (Support\Proprietor), so a withdrawal can never be written for
 * somebody else and booked as drawings.
 *
 * @phpstan-type LineInput array{product_id: int|string, quantity: string, note?: ?string}
 */
class SaveOwnerWithdrawal
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function handle(
        Warehouse $warehouse,
        mixed $withdrawnOn,
        array $lines,
        User $actor,
        ?string $note = null,
        ?OwnerWithdrawal $existing = null,
    ): OwnerWithdrawal {
        if ($existing !== null && ! $existing->isEditable()) {
            throw new RuntimeException("Owner personal withdrawal {$existing->number} is ".strtolower($existing->status->label()).' and cannot be edited. Send it back to draft first.');
        }

        $businessId = (int) app(BusinessContext::class)->id();
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);

        $proprietor = Proprietor::userFor($business);

        if ($proprietor === null) {
            throw ValidationException::withMessages(['lines' => 'No proprietor is named for this business. Set "Proprietor" under Settings > Company first.']);
        }

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => 'That warehouse is not active in this business.']);
        }

        $date = Carbon::parse($withdrawnOn)->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['withdrawn_on' => 'An owner personal withdrawal cannot be dated in the future.']);
        }

        $prepared = [];

        foreach (array_values($lines) as $index => $line) {
            $prepared[] = $this->prepare($line, $index);
        }

        if ($prepared === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product.']);
        }

        return DB::transaction(function () use ($business, $businessId, $warehouse, $date, $prepared, $actor, $note, $existing, $proprietor) {
            $withdrawal = $existing ?? new OwnerWithdrawal([
                'business_id' => $businessId,
                'number' => $this->nextNumber($business, $date),
                'status' => OwnerWithdrawalStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $withdrawal->fill([
                'proprietor_user_id' => $proprietor->id,
                'employee_id' => Proprietor::employeeOf($proprietor)?->id,
                'warehouse_id' => $warehouse->id,
                'withdrawn_on' => $date,
                'note' => $note,
                'cost_total_paisa' => 0,
                'reference_total_paisa' => null,
            ])->save();

            $withdrawal->lines()->delete();

            foreach ($prepared as $sort => $line) {
                $withdrawal->lines()->create(['business_id' => $businessId, 'sort' => $sort] + $line);
            }

            return $withdrawal->fresh(['lines.product']);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function prepare(array $line, int $index): array
    {
        $field = fn (string $name) => "lines.{$index}.{$name}";

        $productId = isset($line['product_id']) && $line['product_id'] !== '' ? (int) $line['product_id'] : null;
        $product = $productId === null ? null : Product::query()->whereKey($productId)->first();

        if ($product === null) {
            throw ValidationException::withMessages([$field('product_id') => 'Pick a product of this business.']);
        }

        if (! $product->type->isStocked()) {
            throw ValidationException::withMessages([$field('product_id') => "{$product->name} is a service and holds no stock."]);
        }

        $quantity = trim((string) ($line['quantity'] ?? ''));

        if ($quantity === '' || ! is_numeric($quantity) || (float) $quantity <= 0) {
            throw ValidationException::withMessages([$field('quantity') => 'The quantity must be above zero.']);
        }

        return [
            'product_id' => $product->id,
            'quantity' => number_format((float) $quantity, 4, '.', ''),
            'note' => trim((string) ($line['note'] ?? '')) ?: null,
        ];
    }

    /** `OW-2026-27-0001`, restarting each fiscal year, like the other documents. */
    private function nextNumber(Business $business, Carbon $date): string
    {
        $prefix = 'OW-'.FiscalYear::containing($date, $business)->label().'-';

        return DocumentNumber::next(OwnerWithdrawal::query(), 'number', $prefix);
    }
}
