<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseOrderCharges;
use App\Modules\Purchasing\Support\PurchaseOrderFigures;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatePurchaseOrder
{
    /**
     * Each line carries its quantity and EITHER a unit cost OR a line total
     * (17 Oct 2026, Phase 1 part A); `entry_mode` says which was typed when
     * both are sent. A line with only `unit_cost`, as every caller before
     * that date sent, is a rate entry and behaves exactly as it did.
     *
     * ⭐ Purchase redesign PR1 (18 Oct 2026): a line may also carry
     * `description`, `unit` (the UnitOfMeasure it was typed in),
     * `discount_type` / `discount_value` and `tax_rate`; `$details` carries
     * the rest of the full page (supplier reference, buyer, terms text, the
     * order discount, rounding, charges, payment schedule). Without them the
     * order is exactly the order it always was. PurchaseOrderFigures does the
     * arithmetic; see there for what the stored line value means.
     *
     * @param  array<int, array{product: Product, quantity: string, unit_cost?: ?string, line_total?: ?string, entry_mode?: ?string}>  $lines
     * @param  array<string, mixed>|null  $details
     */
    public function handle(
        Supplier $supplier,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        ?string $expectedDate = null,
        ?string $note = null,
        ?string $orderDate = null,
        // ⭐ Ruling P6: this order's own payment terms; null means the supplier's.
        ?PaymentTerms $terms = null,
        ?array $details = null,
    ): PurchaseOrder {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line item.');
        }

        // ⭐ D-G (Joy, 18 Oct 2026): checked BEFORE the transaction so a refused
        // date never claims a PO number. Null means today.
        $orderDate = self::resolveOrderDate($orderDate);

        // ⚠ Worked out BEFORE the transaction: a refused line, charge or
        // schedule row never claims a PO number.
        $plan = self::plan($supplier, $lines, $orderDate, $details ?? []);

        $order = DB::transaction(function () use ($supplier, $warehouse, $plan, $createdBy, $expectedDate, $note, $orderDate, $terms, $details) {
            // ⚠ 14 Oct 2026 (SAL-001, audit #7): was "how many orders + 1",
            // which repeats a number the moment any order is removed and gave an
            // error page when two were saved together. Now the numerically
            // largest PO number + 1, retried on a collision.
            $order = DocumentNumber::claim(
                'po_number',
                fn () => DocumentNumber::next(PurchaseOrder::query(), 'po_number', 'PO-', 6),
                fn (string $number) => PurchaseOrder::create([
                    'po_number' => $number,
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $warehouse->id,
                    'status' => PurchaseOrderStatus::Open,
                    'order_date' => $orderDate,
                    'expected_date' => $expectedDate,
                    'note' => $note,
                    ...PaymentTerms::columns($terms),
                    'created_by' => $createdBy->id,
                    ...self::headerColumns($details ?? [], $plan, $createdBy),
                ]),
            );

            self::writeChildren($order, $plan);

            $order->submitForApproval($createdBy);

            if ($createdBy->can('purchasing.po.approve')) {
                $order->approvalRequest->approve($createdBy);
            }

            return $order->fresh(['lines', 'approvalRequest']);
        });

        // Only notify when it actually needs review — a self-approved PO doesn't.
        if ($order->approvalRequest->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'purchasing.po.approve',
                summary: "Purchase order {$order->po_number} for {$supplier->name}",
                excludeUserId: $createdBy->id,
            );
        }

        return $order;
    }

    /**
     * The worked lines and totals, the checked charges and the checked
     * payment plan, or a ValidationException under the box at fault.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $details
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>, charges: list<array<string, mixed>>, schedule: list<array<string, mixed>>}
     */
    public static function plan(Supplier $supplier, array $lines, string $orderDate, array $details): array
    {
        $charges = PurchaseOrderCharges::checked($details['charges'] ?? [], count($lines));
        $business = Business::withoutGlobalScopes()->find($supplier->business_id ?? app(BusinessContext::class)->id());

        $figures = app(PurchaseOrderFigures::class)->work(
            array_values($lines),
            $details['discount'] ?? null,
            $charges,
            isset($details['rounding']) ? (string) $details['rounding'] : null,
            (int) $supplier->id,
            $business,
            Carbon::parse($orderDate),
        );

        $schedule = PurchaseOrderCharges::schedule($details['schedule'] ?? [], Money::ofMinor((int) $figures['totals']['grand_total_paisa']));

        return $figures + ['charges' => $charges, 'schedule' => $schedule];
    }

    /**
     * The order's own columns from the full page and the worked totals.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function headerColumns(array $details, array $plan, User $by): array
    {
        $totals = $plan['totals'];

        return [
            'supplier_reference' => trim((string) ($details['supplier_reference'] ?? '')) ?: null,
            // The buyer defaults to whoever raised the order.
            'buyer_id' => $details['buyer_id'] ?? $by->id,
            // ⭐ Initial plan B1 (R40): the HR employee who handled the purchase.
            'purchasing_employee_id' => $details['purchasing_employee_id'] ?? null,
            'terms_text' => trim((string) ($details['terms_text'] ?? '')) ?: null,
            'discount_type' => $totals['discount_type'],
            'discount_value' => $totals['discount_value'],
            'goods_paisa' => $totals['goods_paisa'],
            'discount_paisa' => $totals['discount_paisa'],
            'tax_total' => $totals['tax_total'],
            'charges_paisa' => $totals['charges_paisa'],
            'supplier_charges_paisa' => $totals['supplier_charges_paisa'],
            'rounding_paisa' => $totals['rounding_paisa'],
            'grand_total_paisa' => $totals['grand_total_paisa'],
            'vat_in_cost' => $totals['vat_in_cost'],
        ];
    }

    /**
     * The lines, the charges (a "one item" charge pointing at the line it
     * names) and the payment plan of a planned order.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function writeChildren(PurchaseOrder $order, array $plan): void
    {
        $lineIds = [];

        foreach ($plan['lines'] as $index => $line) {
            $lineIds[$index] = $order->lines()->create([
                'product_id' => $line['product']->id,
                'description' => $line['description'],
                'unit_id' => $line['unit_id'],
                'entered_quantity' => $line['entered_quantity'],
                'entered_rate' => $line['entered_rate'],
                'quantity_ordered' => $line['quantity_ordered'],
                'unit_cost' => $line['unit_cost'],
                'line_total_paisa' => $line['line_total_paisa'],
                'entry_mode' => $line['entry_mode'],
                'gross_paisa' => $line['gross_paisa'],
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_paisa' => $line['discount_paisa'],
                'order_discount_paisa' => $line['order_discount_paisa'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
            ])->id;
        }

        foreach ($plan['charges'] as $sort => $charge) {
            $index = $charge['line_index'];
            unset($charge['line_index']);

            $order->charges()->create($charge + [
                'sort' => $sort,
                'purchase_order_line_id' => $index === null ? null : ($lineIds[$index] ?? null),
            ]);
        }

        foreach ($plan['schedule'] as $sort => $row) {
            $order->paymentSchedule()->create($row + ['sort' => $sort]);
        }
    }

    /**
     * The day the order was placed, as Y-m-d in Dhaka's calendar.
     *
     * ⭐ D-G (Joy, 18 Oct 2026): "many order done previous date but add later".
     * An earlier day is fine; a later one is not, because an order dated
     * tomorrow has not been placed yet. A day inside closed books is refused by
     * the one period check every document uses, whose sentence names the period.
     *
     * ⚠ The checks live here, not only on the screen, because this is the one
     * door every purchase order comes through.
     */
    public static function resolveOrderDate(?string $orderDate): string
    {
        $today = now()->toDateString();

        if ($orderDate === null || trim($orderDate) === '') {
            return $today;
        }

        $day = Carbon::parse(trim($orderDate))->startOfDay();

        if ($day->toDateString() > $today) {
            throw new InvalidArgumentException("The order date {$day->toDateString()} is in the future: an order is dated the day it was placed.");
        }

        AccountingPeriod::assertOpenFor($day);

        return $day->toDateString();
    }
}
