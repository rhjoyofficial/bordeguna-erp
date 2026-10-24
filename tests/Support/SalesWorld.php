<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\CounterSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * ⭐ The sales side of the corrections world (corrections wave 1, 20 Oct 2026):
 * the CorrectionWorld (business, chart, Owner, three Finance people, Manager,
 * the correction workflows, the Tk 20,000 limit) plus the sales and stock
 * mappings, the sales permissions, a Staff cashier, Savar warehouse, honey in
 * stock at Tk 500 a jar, and Karim Traders. Every document is made through the
 * real actions.
 */
final class SalesWorld
{
    public CorrectionWorld $w;

    public Warehouse $warehouse;

    public Product $honey;

    public Customer $customer;

    public User $cashier;

    public static function make(?CorrectionWorld $base = null): self
    {
        $world = new self;
        $world->w = $base ?? CorrectionWorld::make();
        $business = $world->w->business;

        Role::firstOrCreate(['name' => 'Staff', 'business_id' => $business->id, 'guard_name' => 'web']);
        (new SalesAccountMappingSeeder)->run();
        (new InventoryAccountMappingSeeder)->run();
        (new SalesPermissionsSeeder)->run();
        $world->w->enter($business);

        $world->cashier = $world->w->member('Staff', 'Rina');
        $world->warehouse = Warehouse::create(['business_id' => $business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
        $unit = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Jar', 'abbreviation' => 'jar']);
        $world->honey = Product::create([
            'business_id' => $business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'HONEY-500',
            'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
        ]);
        // ⭐ 25 Oct 2026 (Q11 = A): a dealer with a blank limit is cash only, so
        // the trade customer these worlds sell to on credit carries a limit.
        $world->customer = Customer::create(['business_id' => $business->id, 'name' => 'Karim Traders', 'type' => 'dealer', 'credit_limit' => '10000000']);

        app(RecordStockIn::class)->handle($world->honey, $world->warehouse, '100', '500', $world->w->owner, occurredAt: '2026-10-01 09:00:00');

        return $world;
    }

    public function order(string $quantity = '10', string $price = '850', ?User $by = null, string $date = '2026-10-10'): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->honey, 'quantity' => $quantity, 'unit_price' => $price]],
            createdBy: $by ?? $this->w->owner,
            warehouse: $this->warehouse,
            orderedAt: $date,
        );
    }

    /** A challan against the order (or a direct one when no order is given). */
    public function challan(?SalesOrder $order = null, string $quantity = '10', string $date = '2026-10-12'): Delivery
    {
        $line = $order === null ? null : $order->lines()->first();

        return app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => $quantity, 'orderLine' => $line]],
            createdBy: $this->w->owner,
            deliveredAt: $date,
        );
    }

    public function invoice(Delivery $delivery, string $price = '850', string $date = '2026-10-13', ?string $quantity = null): SalesInvoice
    {
        $line = $delivery->lines()->first();

        return app(RecordSalesInvoice::class)->handle(
            customer: $this->customer,
            lines: [['deliveryLine' => $line, 'quantity' => $quantity ?? (string) $line->quantity, 'unitPrice' => $price]],
            createdBy: $this->w->financeA,
            invoiceDate: $date,
        );
    }

    public function receive(SalesInvoice $invoice, string $amount, string $date = '2026-10-14', ?User $by = null): CustomerReceipt
    {
        return app(RecordCustomerReceipt::class)->handle(
            customer: $this->customer,
            moneyAccount: $this->w->bank,
            receivedOn: $date,
            allocations: [['invoice' => $invoice, 'amount' => $amount]],
            createdBy: $by ?? $this->w->financeA,
        );
    }

    public function counterSale(string $quantity = '2', ?User $by = null, ?string $at = null): CounterSale
    {
        return app(RecordCounterSale::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => $quantity, 'unitPrice' => '850']],
            createdBy: $by ?? $this->cashier,
            moneyAccount: $this->w->cash,
            soldAt: $at,
        );
    }

    public function input(string $type, Model $document, DocumentAction $action, ?array $replacement = null, string $reason = 'Typed on the wrong record', ReverseDateChoice $choice = ReverseDateChoice::OriginalDate): CorrectionInput
    {
        return new CorrectionInput($type, (int) $document->getKey(), $action, new ActionReason(null, $reason), $replacement, $choice, (string) Str::uuid());
    }

    public function preview(CorrectionInput $input, User $by): CorrectionPreview
    {
        auth()->setUser($by);

        return app(CorrectionService::class)->preview($input, $by);
    }

    public function submit(CorrectionInput $input, User $by, ?string $ack = null): CorrectionResult
    {
        $preview = $this->preview($input, $by);

        return app(CorrectionService::class)->submit($input, $by, $preview->previewToken, $ack);
    }

    /** On hand, all batches of the honey. */
    public function onHand(): float
    {
        return (float) StockBatch::query()->where('product_id', $this->honey->id)->sum('current_quantity');
    }

    public function balance(string $slug): int
    {
        return $this->w->balance(Account::bySlug($slug));
    }

    /**
     * The sub-ledger reconciliation's problems for this business on the sales
     * side (AR in total and per customer, GDNI, COGS). Stock on Hand is left
     * out: this world's opening stock is a bare stock-in with no journal.
     */
    public function reconciliationProblems(): array
    {
        return array_values(array_filter(
            SubledgerReconciliation::reconcileCurrentBusiness()['problems'],
            fn (string $problem) => ! str_contains($problem, 'Stock on Hand'),
        ));
    }

    public function today(string $date): void
    {
        Carbon::setTestNow($date);
    }
}
