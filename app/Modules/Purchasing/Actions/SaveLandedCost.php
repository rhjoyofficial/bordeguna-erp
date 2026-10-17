<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\Supplier;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Writes or rewrites a DRAFT landed cost with its lines. Nothing here touches
 * stock or the ledger: that is PostLandedCost, after approval.
 *
 * Rules enforced here, per line (ruling Q5):
 *  - the treatment is the type's seed default unless an override_reason is
 *    given; an override never changes the default for the next document;
 *  - the payee is ONE of a supplier or a money account;
 *  - a direct allocation names one line of THIS receipt; a value allocation
 *    names none;
 *  - an expensed "other" line names its expense head.
 *
 * @phpstan-type LineInput array{
 *   cost_type: string, treatment: ?string, override_reason: ?string,
 *   allocation_method: ?string, goods_receipt_line_id: ?int, amount: string,
 *   supplier_id: ?int, money_account_id: ?int, expense_account_id: ?int, description: ?string
 * }
 */
class SaveLandedCost
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function handle(
        GoodsReceipt $receipt,
        mixed $incurredOn,
        array $lines,
        User $actor,
        ?string $note = null,
        ?LandedCost $existing = null,
    ): LandedCost {
        if ($existing !== null && ! $existing->isEditable()) {
            throw new RuntimeException("Landed cost {$existing->number} is {$this->stateOf($existing)} and cannot be edited.");
        }

        if ($existing !== null && (int) $existing->goods_receipt_id !== (int) $receipt->id) {
            throw new RuntimeException("Landed cost {$existing->number} belongs to another goods receipt.");
        }

        $date = Carbon::parse($incurredOn)->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['incurred_on' => 'A landed cost cannot be dated in the future.']);
        }

        $receiptLineIds = $receipt->lines()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $prepared = [];

        foreach (array_values($lines) as $index => $line) {
            $prepared[] = $this->prepare($line, $index, $receiptLineIds);
        }

        if ($prepared === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one cost line.']);
        }

        $businessId = (int) app(BusinessContext::class)->id();

        return DB::transaction(function () use ($receipt, $date, $prepared, $actor, $note, $existing, $businessId) {
            $total = Money::sum(array_map(fn (array $line) => Money::ofMinor($line['amount_paisa']), $prepared));

            $landedCost = $existing ?? new LandedCost([
                'business_id' => $businessId,
                'number' => $this->nextNumber($businessId, $date),
                'goods_receipt_id' => $receipt->id,
                'status' => LandedCostStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $landedCost->fill([
                'incurred_on' => $date,
                'note' => $note,
                'total_paisa' => $total->minor,
            ])->save();

            $landedCost->lines()->delete();

            foreach ($prepared as $sort => $line) {
                $landedCost->lines()->create(['business_id' => $landedCost->business_id, 'sort' => $sort] + $line);
            }

            return $landedCost->fresh(['lines', 'approvalRequest']);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<int>  $receiptLineIds
     * @return array<string, mixed>
     */
    private function prepare(array $line, int $index, array $receiptLineIds): array
    {
        $field = fn (string $name) => "lines.{$index}.{$name}";

        $type = LandedCostType::tryFrom((string) ($line['cost_type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages([$field('cost_type') => 'Pick what the cost was for.']);
        }

        $treatment = ($line['treatment'] ?? null) === null || $line['treatment'] === ''
            ? $type->defaultTreatment()
            : LandedCostTreatment::tryFrom((string) $line['treatment']);

        if ($treatment === null) {
            throw ValidationException::withMessages([$field('treatment') => 'Pick a treatment.']);
        }

        $reason = trim((string) ($line['override_reason'] ?? ''));

        // ⚠ Ruling Q5: an override of the seed default is per line, logged and
        // reasoned; a bank charge capitalised "because it relates to a
        // purchase" is exactly what the reason has to argue for.
        if ($treatment !== $type->defaultTreatment() && $reason === '') {
            throw ValidationException::withMessages([
                $field('override_reason') => $type->label().' is '.strtolower($type->defaultTreatment()->label()).' by default. Give the reason for treating it as '.strtolower($treatment->label()).'.',
            ]);
        }

        $amount = Money::ofTaka((string) ($line['amount'] ?? '0'));

        if ($amount->minor <= 0) {
            throw ValidationException::withMessages([$field('amount') => 'The amount must be above zero.']);
        }

        $supplierId = isset($line['supplier_id']) && $line['supplier_id'] !== '' && $line['supplier_id'] !== null ? (int) $line['supplier_id'] : null;
        $moneyAccountId = isset($line['money_account_id']) && $line['money_account_id'] !== '' && $line['money_account_id'] !== null ? (int) $line['money_account_id'] : null;

        if (($supplierId === null) === ($moneyAccountId === null)) {
            throw ValidationException::withMessages([$field('supplier_id') => 'Name who is owed (a supplier) or who was paid from (a money account), one of the two.']);
        }

        if ($supplierId !== null && ! Supplier::query()->whereKey($supplierId)->exists()) {
            throw ValidationException::withMessages([$field('supplier_id') => 'That supplier is not in this business.']);
        }

        if ($moneyAccountId !== null) {
            $moneyAccount = MoneyAccount::query()->whereKey($moneyAccountId)->first();

            if ($moneyAccount === null || ! $moneyAccount->is_active) {
                throw ValidationException::withMessages([$field('money_account_id') => 'That money account is not active in this business.']);
            }

            if (($refusal = $moneyAccount->handRefusal()) !== null) {
                throw ValidationException::withMessages([$field('money_account_id') => $refusal]);
            }
        }

        $method = LandedCostAllocationMethod::tryFrom((string) ($line['allocation_method'] ?? LandedCostAllocationMethod::Value->value));

        if ($method === null) {
            throw ValidationException::withMessages([$field('allocation_method') => 'Pick how the cost spreads over the receipt.']);
        }

        $targetId = isset($line['goods_receipt_line_id']) && $line['goods_receipt_line_id'] !== '' && $line['goods_receipt_line_id'] !== null ? (int) $line['goods_receipt_line_id'] : null;

        if ($treatment === LandedCostTreatment::Capitalize) {
            if ($method === LandedCostAllocationMethod::Direct && ($targetId === null || ! in_array($targetId, $receiptLineIds, true))) {
                throw ValidationException::withMessages([$field('goods_receipt_line_id') => 'Pick the line of this receipt the cost belongs to.']);
            }

            if ($method === LandedCostAllocationMethod::Value) {
                $targetId = null;
            }
        } else {
            $targetId = null;
        }

        $expenseAccountId = isset($line['expense_account_id']) && $line['expense_account_id'] !== '' && $line['expense_account_id'] !== null ? (int) $line['expense_account_id'] : null;

        if ($treatment === LandedCostTreatment::Expense && $type === LandedCostType::Other && $expenseAccountId === null) {
            throw ValidationException::withMessages([$field('expense_account_id') => 'Name the expense head an "other" cost belongs to.']);
        }

        if ($treatment === LandedCostTreatment::Capitalize) {
            $expenseAccountId = null;
        }

        return [
            'cost_type' => $type,
            'treatment' => $treatment,
            'override_reason' => $treatment !== $type->defaultTreatment() ? $reason : null,
            'allocation_method' => $method,
            'goods_receipt_line_id' => $targetId,
            'amount_paisa' => $amount->minor,
            'supplier_id' => $supplierId,
            'money_account_id' => $moneyAccountId,
            'expense_account_id' => $expenseAccountId,
            'description' => trim((string) ($line['description'] ?? '')) ?: null,
        ];
    }

    private function stateOf(LandedCost $landedCost): string
    {
        return $landedCost->isAwaitingApproval() ? 'waiting for approval' : strtolower($landedCost->status->label());
    }

    /** `LC-2026-27-0001`, restarting each fiscal year, like expense vouchers. */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'LC-'.FiscalYear::containing($date, $business)->label().'-';

        return DocumentNumber::next(LandedCost::query(), 'number', $prefix);
    }
}
