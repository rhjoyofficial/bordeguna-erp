<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Carriage, unloading and other costs printed on a supplier's bill, sent
 * through the landed-cost engine (ruling P5, 19 Oct 2026; IAS 2 cost types
 * from release 3).
 *
 * ⚠⚠ ONE CHARGE, ONE LIABILITY. The amounts here are NOT in the bill's total
 * and NOT in its journal. Each becomes a landed cost on the goods receipt it
 * belongs to, owed to the bill's supplier, named back to the bill
 * (`landed_costs.supplier_bill_id`). When that landed cost posts, ITS journal
 * puts the cost into stock (or the expense head its type defaults to) and
 * credits Accounts Payable, and PostLandedCost raises the supplier bill a
 * payment settles. Recording it here as well would owe the same carriage
 * twice and capitalise it twice; the ruling forbids exactly that.
 *
 * The landed-cost workflow is not bypassed: one draft per receipt, sent for
 * approval if the person may send it, approved at once only within their own
 * authority (SubmitLandedCost), and posted at once only if they may post.
 * Anything the engine refuses (a date before the goods arrived, Joy's
 * Tk 10,000 rule, a closed month) leaves the landed cost as a draft on the
 * Landed costs page with its reason, never a bill that fails for it.
 */
class RaiseBillCarriage
{
    public function __construct(
        private readonly SaveLandedCost $save,
        private readonly SubmitLandedCost $submit,
        private readonly PostLandedCost $post,
    ) {}

    /**
     * Each line: goods_receipt_id, cost_type, amount, and optionally
     * allocation_method, goods_receipt_line_id, description, treatment and
     * override_reason, as the Landed costs screen takes them.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, LandedCost>
     */
    public function handle(SupplierBill $bill, array $lines, User $by): Collection
    {
        $byReceipt = [];

        foreach (array_values($lines) as $index => $line) {
            $receiptId = (int) ($line['goods_receipt_id'] ?? 0);
            $type = LandedCostType::tryFrom((string) ($line['cost_type'] ?? ''));

            if ($receiptId === 0 || GoodsReceipt::query()->whereKey($receiptId)->doesntExist()) {
                throw new InvalidArgumentException('Carriage line '.($index + 1).': pick the delivery (goods receipt) the cost belongs to.');
            }

            if ($type === null) {
                throw new InvalidArgumentException('Carriage line '.($index + 1).': pick what the cost was for.');
            }

            if (Money::ofTaka((string) ($line['amount'] ?? '0'))->minor <= 0) {
                throw new InvalidArgumentException('Carriage line '.($index + 1).': the amount must be above zero.');
            }

            $byReceipt[$receiptId][] = [
                'cost_type' => $type->value,
                'treatment' => $line['treatment'] ?? null,
                'override_reason' => $line['override_reason'] ?? null,
                'allocation_method' => $line['allocation_method'] ?? LandedCostAllocationMethod::Value->value,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                'amount' => (string) $line['amount'],
                // ⚠ Owed to THIS bill's supplier: the engine's Cr Accounts Payable.
                'supplier_id' => $bill->supplier_id,
                'money_account_id' => null,
                'expense_account_id' => $line['expense_account_id'] ?? null,
                'description' => $line['description'] ?? null,
            ];
        }

        $raised = collect();

        foreach ($byReceipt as $receiptId => $receiptLines) {
            try {
                $landedCost = $this->save->handle(
                    receipt: GoodsReceipt::query()->findOrFail($receiptId),
                    incurredOn: $bill->bill_date,
                    lines: $receiptLines,
                    actor: $by,
                    note: "From supplier bill {$bill->bill_number}",
                );
            } catch (ValidationException $e) {
                // The engine's field keys belong to the Landed costs screen.
                throw new InvalidArgumentException('Carriage on goods receipt #'.$receiptId.': '.collect($e->errors())->flatten()->first());
            }

            $landedCost->forceFill(['supplier_bill_id' => $bill->id])->save();
            $raised->push($this->sendOn($landedCost, $by));
        }

        return $raised;
    }

    /**
     * As far through the workflow as the person's own permissions reach; a
     * refusal leaves the document where it stopped, with the engine's reason
     * shown on the Landed costs page when it is opened.
     */
    private function sendOn(LandedCost $landedCost, User $by): LandedCost
    {
        if (! $by->can('purchasing.landed_cost.manage')) {
            return $landedCost;
        }

        try {
            $landedCost = $this->submit->handle($landedCost, $by);

            if ($landedCost->status === LandedCostStatus::Approved && $by->can('purchasing.landed_cost.post')) {
                $landedCost = $this->post->handle($landedCost, $by);
            }
        } catch (QueryException $e) {
            throw $e;
        } catch (PurchaseApprovalRequired) {
            // ⭐ 24 Oct 2026: owed to the supplier at or above the purchasing
            // limit (ruling P7): left approved, Post on the Landed costs
            // screen sends it for the second person.
            return $landedCost->fresh();
        } catch (RuntimeException) {
            return $landedCost->fresh();
        }

        return $landedCost;
    }
}
