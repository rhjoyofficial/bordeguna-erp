<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderImport;
use App\Modules\Sales\Models\SalesOrderImportOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Modules\Sales\Support\ShopOrderImportPlan;
use App\Modules\Sales\Support\StagedShopOrderFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes the orders a shop order sheet's plan describes (Joy, 11 Oct 2026).
 *
 * ⚠⚠ EVERY ORDER GOES THROUGH CreateSalesOrder. The price engine, the frozen
 * delivery charge, the address, the auto weight and the approval request are
 * the same as an order typed on the screen; nothing here writes an order row
 * of its own.
 *
 * ⚠ One transaction PER ORDER, so one order the database refuses (a shop
 * number imported a moment ago by somebody else) costs that order only, and
 * the import records why.
 *
 * ⚠ Approval: every order is created WAITING. With "auto-approve" (only for a
 * holder of `sales.orders.approve`) each is then approved through
 * ApproveSalesOrder, so the credit rules apply exactly as on the screen; one
 * they refuse stays waiting. Approvers get one notice for the whole file.
 */
class ApplyShopOrderImport
{
    public function __construct(
        private readonly CreateSalesOrder $create,
        private readonly RecordOrderPayment $payments,
        private readonly ApproveSalesOrder $approve,
    ) {}

    /**
     * @param  array<string, MoneyAccount>  $moneyAccounts  advance method => the account it was paid into
     *
     * @throws ValidationException
     */
    public function handle(
        ShopOrderImportPlan $plan,
        StagedShopOrderFile $file,
        string $fileName,
        int $businessId,
        User $by,
        bool $autoApprove,
        array $moneyAccounts,
    ): SalesOrderImport {
        if ($plan->hasErrors()) {
            throw ValidationException::withMessages(['file' => 'That file has '.count($plan->errors).' error(s), so no orders were imported.']);
        }

        $autoApprove = $autoApprove && $by->can('sales.orders.approve');

        $first = SalesOrderImport::query()->where('business_id', $businessId)->where('file_sha256', $file->hash)->first();

        if ($first !== null) {
            throw ValidationException::withMessages(['file' => "This exact file was already imported on {$first->created_at?->format('Y-m-d H:i')} (import #{$first->id})."]);
        }

        $path = $file->keep($businessId);

        try {
            $import = SalesOrderImport::create([
                'business_id' => $businessId,
                'file_name' => mb_substr($fileName, 0, 255),
                'file_path' => $path,
                'file_sha256' => $file->hash,
                'file_size' => $file->size,
                'rows' => $plan->rows,
                'auto_approve' => $autoApprove,
                'money_mappings' => array_map(fn (MoneyAccount $a) => $a->id, $moneyAccounts),
                'created_by' => $by->id,
            ]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['file' => 'This exact file was imported a moment ago. Open the order list to see it.']);
        }

        $created = $skipped = $failed = 0;
        $waiting = 0;

        foreach ($plan->skipped as $skip) {
            $this->record($import, $skip['order_ref'], SalesOrderImportOrder::SKIPPED, "Already imported as {$skip['order_number']}.", $skip['sales_order_id']);
            $skipped++;
        }

        foreach ($plan->orders as $order) {
            try {
                $salesOrder = DB::transaction(fn () => $this->createOne($order, $import, $businessId, $by, $moneyAccounts));
            } catch (QueryException $e) {
                // The unique shop number: somebody imported it in the meantime.
                $existing = SalesOrder::query()->withoutGlobalScopes()->where('shop_reference', $order['order_ref'])->first();

                if ($existing !== null && (int) $existing->business_id === $businessId) {
                    $this->record($import, $order['order_ref'], SalesOrderImportOrder::SKIPPED, "Already imported as {$existing->order_number}.", (int) $existing->id, $order);
                    $skipped++;
                } else {
                    report($e);
                    $this->record($import, $order['order_ref'], SalesOrderImportOrder::FAILED, 'The database refused this order.', null, $order);
                    $failed++;
                }

                continue;
            } catch (Throwable $e) {
                $message = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
                $this->record($import, $order['order_ref'], SalesOrderImportOrder::FAILED, Str::limit($message, 480, ''), null, $order);
                $failed++;

                continue;
            }

            $message = null;

            if ($autoApprove) {
                try {
                    $this->approve->handle($salesOrder->fresh(), $by);
                } catch (Throwable $e) {
                    $message = Str::limit('Waiting for approval: '.$e->getMessage(), 480, '');
                }
            }

            if ($salesOrder->fresh()->approvalRequest?->isPending()) {
                $waiting++;
            }

            $this->record($import, $order['order_ref'], SalesOrderImportOrder::CREATED, $message, (int) $salesOrder->id, $order);
            $created++;
        }

        $import->forceFill(['orders_created' => $created, 'orders_skipped' => $skipped, 'orders_failed' => $failed])->save();

        if ($waiting > 0) {
            app(NotifyApprovers::class)->handle(
                businessId: $businessId,
                permission: 'sales.orders.approve',
                summary: "{$waiting} shop order(s) imported from {$import->file_name} wait for approval",
                excludeUserId: $autoApprove ? null : $by->id,
            );
        }

        return $import->fresh('orders');
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, MoneyAccount>  $moneyAccounts
     */
    private function createOne(array $order, SalesOrderImport $import, int $businessId, User $by, array $moneyAccounts): SalesOrder
    {
        $customer = $this->customer($order, $businessId);
        $store = $order['store'];
        $method = $order['advance_method'];
        $account = $method === null ? null : ($moneyAccounts[$method] ?? null);
        /** @var Money $advance */
        $advance = $order['advance'];

        $notes = array_filter([
            $order['note'],
            $order['alt_phone'] !== null ? "Alternative phone {$order['alt_phone']}." : null,
            "Shop order {$order['order_ref']} ({$order['channel']}), imported from {$import->file_name}.",
            $advance->minor > 0 && $account === null
                ? "Advance {$advance->format()} by {$method}".($order['advance_reference'] ? " (ref {$order['advance_reference']})" : '').' NOT recorded as a receipt: no money account was chosen for it.'
                : null,
        ]);

        $salesOrder = $this->create->handle(
            customer: $customer,
            lines: array_map(fn (array $line) => [
                'product' => $line['product'] instanceof Product ? $line['product'] : Product::query()->findOrFail($line['product']),
                'quantity' => $line['quantity'],
                'unit_price' => $line['override'] ? $line['unit_price']->toTaka() : null,
                'discount' => $line['discount']->toTaka(),
                'price_note' => $line['override']
                    ? 'Imported from shop (list price '.($line['list_price']?->format() ?? 'none').')'
                    : null,
            ], $order['lines']),
            createdBy: $by,
            orderedAt: $order['order_date'],
            note: implode(' ', $notes),
            origin: new SalesOrderOrigin(
                channel: $order['channel'] === 'website' ? SalesChannel::Website : SalesChannel::Internal,
                storeId: $store?->id,
                shopReference: $order['order_ref'],
                priceGroupId: $store?->price_group_id,
                deliveryCharge: $order['delivery_charge']?->toTaka(),
                paymentMethod: $order['payment_method'],
                destination: $order['destination'],
                pricesAsChannel: $store !== null,
                orderDiscount: $order['order_discount']->toTaka(),
            ),
            weightGrams: $order['weight_grams'],
            brandId: $order['brand_id'] === null ? null : (int) $order['brand_id'],
            selfApprove: false,
            notifyApprovers: false,
        );

        if ($advance->minor > 0 && $account !== null) {
            $this->payments->handle(
                order: $salesOrder,
                moneyAccount: $account,
                receivedOn: $order['order_date'],
                amount: $advance->toTaka(),
                createdBy: $by,
                reference: $order['advance_reference'] !== null ? mb_substr($order['advance_reference'], 0, 255) : null,
                note: "Advance for shop order {$order['order_ref']} ({$method})",
            );
        }

        return $salesOrder;
    }

    /**
     * The website's rule (ConfirmHqSalesOrderDraft::identify): the first active,
     * non-system customer of the business with this phone; otherwise a new
     * Retail customer. An existing customer is never rewritten.
     *
     * @param  array<string, mixed>  $order
     */
    private function customer(array $order, int $businessId): Customer
    {
        $existing = Customer::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)->where('phone_normalized', $order['phone'])
            ->where('is_active', true)->where('is_system', false)->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        return Customer::create([
            'business_id' => $businessId,
            'name' => mb_substr((string) $order['customer_name'], 0, 255),
            'type' => CustomerType::Retail,
            'phone' => $order['phone'],
            'address' => $order['destination']->oneLineAddress(),
            'price_group_id' => null,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed>|null $order */
    private function record(SalesOrderImport $import, string $ref, string $outcome, ?string $message, ?int $orderId, ?array $order = null): void
    {
        SalesOrderImportOrder::create([
            'business_id' => $import->business_id,
            'sales_order_import_id' => $import->id,
            'order_ref' => $ref,
            'sales_order_id' => $orderId,
            'brand_id' => $order['brand_id'] ?? null,
            'brand_name' => $order['brand_name'] ?? null,
            'source_channel' => $order['channel'] ?? null,
            'outcome' => $outcome,
            'message' => $message,
        ]);
    }
}
