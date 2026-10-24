<?php

namespace Tests\Support;

use App\Modules\Accounting\Actions\AuthoriseOwnerWithdrawal;
use App\Modules\Accounting\Actions\PostOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveOwnerWithdrawal;
use App\Modules\Accounting\Corrections\OwnerWithdrawalActionPolicy;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Corrections\ExpenseVoucherActionPolicy;
use App\Modules\Purchasing\Corrections\LandedCostActionPolicy;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Initial plan B24 (25 Oct 2026): records in every state for the policy kit
 * (PolicyKitTest 40): received and reversed stock transfers, draft, posted
 * and reversed owner withdrawals and landed costs. Codes carry "-G" so they
 * never meet the other kits' records in the shared business.
 */
final class ReverseGapsKit
{
    /** @var array<int, array<string, list<array{0: Model, 1: string}>>> */
    private static array $built = [];

    /** @return array<string, list<array{0: Model, 1: string}>> */
    public static function records(CorrectionWorld $w): array
    {
        $key = spl_object_id($w);

        if (isset(self::$built[$key])) {
            return self::$built[$key];
        }

        $s = StockWorld::make($w, '-G');
        $s->opening([[$s->ghee, '20', '900']]);
        (new PurchasingPermissionsSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $w->enter($w->business);
        $w->business->update(['proprietor_user_id' => $w->owner->id]);

        // Stock transfers: received, and received then reversed.
        $arrived = function () use ($s, $w) {
            $transfer = app(DispatchStockTransfer::class)->handle(product: $s->ghee, source: $s->savar, target: $s->shop, quantity: '1',
                reason: $s->reason('Warehouse rebalancing', 'inventory.stock_transfer'), performedBy: $w->manager, occurredAt: '2026-10-10');
            app(ReceiveStockTransfer::class)->handle($transfer, $w->manager, '2026-10-11');

            return $transfer->fresh();
        };
        $received = $arrived();
        $reversedTransfer = $arrived();
        $s->submit($s->input(StockTransferActionPolicy::TYPE, $reversedTransfer, DocumentAction::Reverse), $w->manager);

        // Owner withdrawals: a draft, a posted one, a reversed one.
        $withdraw = fn () => app(SaveOwnerWithdrawal::class)->handle($s->savar, '2026-10-15', [['product_id' => $s->ghee->id, 'quantity' => '1']], $w->owner);
        $post = function () use ($withdraw, $w) {
            $draft = $withdraw();
            app(AuthoriseOwnerWithdrawal::class)->handle($draft, $w->financeA, null);

            return app(PostOwnerWithdrawal::class)->handle($draft->fresh(), $w->owner);
        };
        $draftWithdrawal = $withdraw();
        $postedWithdrawal = $post();
        $reversedWithdrawal = $post();
        $s->submit($s->input(OwnerWithdrawalActionPolicy::TYPE, $reversedWithdrawal, DocumentAction::Reverse), $w->financeB);

        // Landed costs: a draft, a posted one, a reversed one.
        $salt = Product::create(['business_id' => $w->business->id, 'unit_of_measure_id' => $s->salt->unit_of_measure_id, 'sku' => 'SALT-LC-G', 'name' => 'Salt for landed cost', 'type' => ProductType::RawMaterial]);
        $supplier = Supplier::create(['business_id' => $w->business->id, 'name' => 'Chattogram Freight', 'code' => 'SUP-G01']);
        $landed = function (bool $post) use ($s, $w, $salt, $supplier): LandedCost {
            $order = app(CreatePurchaseOrder::class)->handle(supplier: $supplier, warehouse: $s->savar, lines: [['product' => $salt, 'quantity' => '10', 'unit_cost' => '20.00']], createdBy: $w->owner);
            approveIfPending($order->approvalRequest, $w->owner);
            $order = $order->fresh(['lines']);
            app(RecordGoodsReceipt::class)->handle($order, $order->lines->map(fn ($line) => ['line' => $line, 'quantity' => (string) $line->quantity_ordered])->all(), $w->owner,
                receivedOn: Carbon::parse('2026-10-10'), backdateReason: 'kit');
            $receipt = $order->goodsReceipts()->with('lines')->latest('id')->firstOrFail();
            $lc = app(SaveLandedCost::class)->handle($receipt, '2026-10-11', [[
                'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
                'goods_receipt_line_id' => null, 'amount' => '100', 'supplier_id' => $supplier->id,
                'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Kit freight',
            ]], $w->owner);

            if (! $post) {
                return $lc;
            }

            $lc = app(SubmitLandedCost::class)->handle($lc, $w->owner);

            return app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $w->owner);
        };
        $draftLanded = $landed(false);
        $postedLanded = $landed(true);
        $reversedLanded = $landed(true);
        $s->submit($s->input(LandedCostActionPolicy::TYPE, $reversedLanded, DocumentAction::Reverse), $w->manager);

        // Expense vouchers: a live one and a reversed one (B24: Reverse through the engine).
        $spend = fn () => app(RecordExpenseVoucher::class)->handle(moneyAccount: $w->cash, spentOn: '2026-10-15',
            lines: [['account' => Account::where('code', '6051')->sole(), 'amount' => '500.00', 'description' => 'Kit rent']], createdBy: $w->owner, paidTo: 'Kit landlord');
        $liveVoucher = $spend();
        $reversedVoucher = $spend();
        $s->submit($s->input(ExpenseVoucherActionPolicy::TYPE, $reversedVoucher, DocumentAction::Reverse), $w->manager);

        return self::$built[$key] = [
            StockTransferActionPolicy::TYPE => [[$received->fresh(), 'received'], [$reversedTransfer->fresh(), 'reversed']],
            OwnerWithdrawalActionPolicy::TYPE => [[$draftWithdrawal->fresh(), 'draft'], [$postedWithdrawal->fresh(), 'posted'], [$reversedWithdrawal->fresh(), 'reversed']],
            LandedCostActionPolicy::TYPE => [[$draftLanded->fresh(), 'draft'], [$postedLanded->fresh(), 'posted'], [$reversedLanded->fresh(), 'reversed']],
            ExpenseVoucherActionPolicy::TYPE => [[$liveVoucher->fresh(), 'live'], [$reversedVoucher->fresh(), 'reversed']],
        ];
    }
}
