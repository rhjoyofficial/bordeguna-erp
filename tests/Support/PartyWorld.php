<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Actions\LinkSupplierCustomer;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\SetOffs;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ⭐ One firm that is our supplier AND our customer (decision D-F, 18 Oct 2026):
 * the CorrectionWorld (business, chart, Owner and three Finance people, the
 * correction workflows) plus AR/AP mappings, the voucher workflow a set-off is
 * approved through, the purchasing and sales read permissions, and Rahman
 * Traders on both sides of the books, linked.
 */
final class PartyWorld
{
    public CorrectionWorld $w;

    public Supplier $supplier;

    public Customer $customer;

    private int $sequence = 0;

    public static function make(?CorrectionWorld $base = null): self
    {
        $world = new self;
        $world->w = $base ?? CorrectionWorld::make();
        $business = $world->w->business;

        (new SalesAccountMappingSeeder)->run();
        (new InventoryAccountMappingSeeder)->run();
        (new VoucherWorkflowSeeder)->run();
        (new PurchasingPermissionsSeeder)->run();
        (new SalesPermissionsSeeder)->run();
        $world->w->enter($business);

        $world->supplier = Supplier::create(['business_id' => $business->id, 'code' => 'RAHMAN', 'name' => 'Rahman Traders', 'phone' => '01711000000', 'address' => 'Mirpur 10, Dhaka']);
        $world->customer = Customer::create(['business_id' => $business->id, 'code' => 'RAHMAN-C', 'name' => 'Rahman Traders', 'type' => 'dealer']);
        app(LinkSupplierCustomer::class)->link($world->supplier, $world->customer, $world->w->financeA);
        $world->customer->refresh();

        return $world;
    }

    /** A posted supplier bill: Cr 2110 AP (supplier) / Dr capital. */
    public function bill(string $total, string $date = '2026-10-01', ?Supplier $supplier = null): SupplierBill
    {
        $supplier ??= $this->supplier;
        $bill = SupplierBill::create([
            'business_id' => $supplier->business_id, 'supplier_id' => $supplier->id, 'bill_number' => 'SB-'.(++$this->sequence),
            'bill_date' => $date, 'total' => $total, 'created_by' => $this->w->owner->id,
        ]);

        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse($date), [
            JournalLineDraft::credit(Account::bySlug('accounts_payable')->id, Money::ofTaka($total), null, $supplier),
            JournalLineDraft::debit(Account::bySlug('owners_capital')->id, Money::ofTaka($total)),
        ], source: $bill), $this->w->owner);

        return $bill;
    }

    /** A posted sales invoice: Dr 1140 AR (customer) / Cr capital. */
    public function invoice(string $total, string $date = '2026-10-01', ?Customer $customer = null): SalesInvoice
    {
        $customer ??= $this->customer;
        $invoice = SalesInvoice::create([
            'business_id' => $customer->business_id, 'customer_id' => $customer->id, 'invoice_number' => 'INV-'.(++$this->sequence),
            'invoice_date' => $date, 'subtotal' => $total, 'total' => $total, 'created_by' => $this->w->owner->id,
        ]);

        app(PostJournal::class)->handle(new JournalDraft(Carbon::parse($date), [
            JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka($total), null, $customer),
            JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka($total)),
        ], source: $invoice), $this->w->owner);

        return $invoice;
    }

    /**
     * @param  array<int, string>  $bills  bill id => taka
     * @param  array<int, string>  $invoices  invoice id => taka
     */
    public function raise(array $bills, array $invoices, ?User $by = null, string $date = '2026-10-15'): SetOff
    {
        return app(SetOffs::class)->raise($this->supplier, $this->customer->fresh(), $date, $invoices, $bills, 'Netted on the phone with Mr Rahman', $by ?? $this->w->financeA);
    }

    public function approve(SetOff $setOff, ?User $by = null, ?string $note = null): SetOff
    {
        app(SetOffs::class)->approve($setOff->fresh(), $by ?? $this->w->owner, $note);

        return $setOff->fresh();
    }

    /** Raised by Finance and approved by the Owner: in the books. */
    public function posted(array $bills, array $invoices): SetOff
    {
        return $this->approve($this->raise($bills, $invoices));
    }

    public function reverse(SetOff $setOff, User $by, string $reason = 'Agreed with the party by mistake'): CorrectionResult
    {
        $input = new CorrectionInput(SetOffActionPolicy::TYPE, (int) $setOff->id, DocumentAction::Reverse, new ActionReason(null, $reason), null, ReverseDateChoice::OriginalDate, (string) Str::uuid());
        $service = app(CorrectionService::class);

        return $service->submit($input, $by, $service->preview($input, $by)->previewToken);
    }

    /** The signed party balance on a head (debits positive). */
    public function balance(string $slug): int
    {
        return $this->w->balance(Account::bySlug($slug));
    }
}
