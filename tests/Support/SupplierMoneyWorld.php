<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionPreview;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierBillActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierPaymentActionPolicy;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): a DiscountWorld (orders received,
 * billed and paid through the real actions) plus the corrections of the four
 * supplier money documents, and the reconciliation every test ends on.
 */
final class SupplierMoneyWorld
{
    public DiscountWorld $d;

    public CorrectionWorld $w;

    public static function make(?CorrectionWorld $w = null): self
    {
        $world = new self;
        $existing = $w === null ? null : Supplier::query()->where('business_id', $w->business->id)->where('code', 'SUP-001')->first();

        if ($existing !== null) {
            // The policy kit already built a DiscountWorld on this world: share it.
            $d = new DiscountWorld;
            $d->w = $w;
            $d->supplier = $existing;
            $d->warehouse = Warehouse::query()->where('code', 'SAV')->firstOrFail();
            $d->salt = Product::query()->where('sku', 'SALT')->firstOrFail();
            $d->sugar = Product::query()->where('sku', 'SUGAR')->firstOrFail();
            $d->service = Product::query()->where('sku', 'LAB')->firstOrFail();
            $world->d = $d;
        } else {
            $world->d = DiscountWorld::make($w);
        }

        $world->w = $world->d->w;

        return $world;
    }

    public static function typeOf(Model $document): string
    {
        return match (true) {
            $document instanceof SupplierBill => SupplierBillActionPolicy::TYPE,
            $document instanceof SupplierPayment => SupplierPaymentActionPolicy::TYPE,
            $document instanceof SupplierCreditNote => SupplierCreditNoteActionPolicy::TYPE,
            $document instanceof PurchaseReturn => PurchaseReturnActionPolicy::TYPE,
        };
    }

    public function input(Model $document, DocumentAction $action, ?array $replacement = null, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate, string $reason = 'Typed it wrong on the screen', ?string $key = null): CorrectionInput
    {
        return new CorrectionInput(self::typeOf($document), (int) $document->getKey(), $action, new ActionReason(null, $reason), $replacement, $choice, $key ?? (string) Str::uuid());
    }

    public function preview(CorrectionInput $input, User $by): CorrectionPreview
    {
        return app(CorrectionService::class)->preview($input, $by);
    }

    public function submit(CorrectionInput $input, User $by): CorrectionResult
    {
        return app(CorrectionService::class)->submit($input, $by, $this->preview($input, $by)->previewToken);
    }

    public function reverse(Model $document, User $by, ReverseDateChoice $choice = ReverseDateChoice::OriginalDate): CorrectionResult
    {
        return $this->submit($this->input($document, DocumentAction::Reverse, null, $choice), $by);
    }

    public function correct(Model $document, User $by, array $replacement): CorrectionResult
    {
        return $this->submit($this->input($document, DocumentAction::Correct, $replacement), $by);
    }

    /** The bill as its Correct form sends it, with changes (`lines` keyed by position). */
    public function billForm(SupplierBill $bill, array $changes = [], array $lineChanges = []): array
    {
        $bill->loadMissing('lines');
        $goods = $bill->lines->reject(fn (SupplierBillLine $l) => $l->isExpense())->values();

        $form = [
            'supplier_id' => $bill->supplier_id,
            'bill_number' => $bill->bill_number,
            'bill_date' => $bill->bill_date->toDateString(),
            'due_date' => null,
            'note' => $bill->note,
            'lines' => $goods->map(fn (SupplierBillLine $l, int $i) => array_merge([
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'goods_receipt_line_id' => $l->goods_receipt_line_id,
                'quantity' => (string) (float) $l->quantity,
                'unit_cost' => (string) (float) $l->unit_cost,
                'line_total' => null,
                'entry_mode' => 'rate',
            ], $lineChanges[$i] ?? []))->all(),
            'services' => $bill->lines->filter(fn (SupplierBillLine $l) => $l->isExpense())->map(fn (SupplierBillLine $l) => [
                'expense_account_id' => $l->expense_account_id,
                'description' => $l->description,
                'amount' => (string) (float) $l->line_total,
            ])->values()->all(),
        ];

        return array_merge($form, $changes);
    }

    public function paymentForm(SupplierPayment $payment, array $changes = []): array
    {
        $payment->loadMissing('allocations');

        return array_merge([
            'paid_on' => $payment->paid_on->toDateString(),
            'money_account_id' => $payment->money_account_id,
            'reference' => $payment->reference,
            'note' => $payment->note,
            'allocations' => $payment->allocations->map(fn ($a) => ['supplier_bill_id' => $a->supplier_bill_id, 'amount' => (string) (float) $a->amount])->all(),
        ], $changes);
    }

    public function creditForm(SupplierCreditNote $note, array $changes = []): array
    {
        $note->loadMissing('allocations');

        return array_merge([
            'credit_note_number' => $note->credit_note_number,
            'credit_date' => $note->credit_date->toDateString(),
            'note' => $note->note,
            'tax_amount' => '0',
            'allocations' => $note->allocations->map(fn ($a) => ['supplier_bill_id' => $a->supplier_bill_id, 'amount' => (string) (float) $a->amount])->all(),
        ], $changes);
    }

    /** Goods back to the supplier from an order, through the real action. */
    public function returnGoods(PurchaseOrder $order, string $quantity, string $on = '2026-10-13'): PurchaseReturn
    {
        Carbon::setTestNow($on.' 11:00:00');
        $return = app(RecordPurchaseReturn::class)->handle($order, [['line' => $order->lines()->first(), 'quantity' => $quantity]], $this->w->owner);
        Carbon::setTestNow('2026-10-17 10:00:00');

        return $return;
    }

    public function creditNote(PurchaseReturn $return, SupplierBill $bill, string $amount, string $number = 'CN-1', string $on = '2026-10-14'): SupplierCreditNote
    {
        return app(RecordSupplierCreditNote::class)->handle(
            supplier: $this->d->supplier,
            purchaseReturn: $return,
            creditNoteNumber: $number,
            creditDate: $on,
            allocations: [['bill' => $bill->fresh(), 'amount' => $amount]],
            createdBy: $this->w->financeA,
        );
    }

    /** What the supplier is owed on Accounts Payable (credit positive), from the ledger. */
    public function payableOwed(): int
    {
        $payable = AccountMapping::resolve('purchasing.accounts_payable', Carbon::today())->id;

        return -(int) DB::table('journal_lines')
            ->where('account_id', $payable)
            ->where('party_type', $this->d->supplier->getMorphClass())
            ->where('party_id', $this->d->supplier->id)
            ->sum('amount_minor');
    }

    /** What the live bills still owe, from the documents. */
    public function billsOwe(): int
    {
        return SupplierBill::query()->where('supplier_id', $this->d->supplier->id)->whereNull('reversed_at')->get()
            ->sum(fn (SupplierBill $bill) => $bill->outstanding()->minor);
    }

    /**
     * The invariants every correction must leave true: every journal balances,
     * the ledger agrees with itself and with every subsidiary ledger (AP per
     * supplier, GRNI, stock), and the supplier ledger view ties to the bills.
     *
     * @return list<string> problems; empty means it reconciles
     */
    public function problems(): array
    {
        $problems = [];

        if ((int) DB::table('journal_lines')->sum('amount_minor') !== 0) {
            $problems[] = 'The journals do not balance.';
        }

        $problems = [...$problems, ...LedgerIntegrity::verifyAllBusinesses()['problems'], ...SubledgerReconciliation::reconcileCurrentBusiness()['problems']];

        if ($this->payableOwed() !== $this->billsOwe()) {
            $problems[] = 'AP for the supplier is '.Money::ofMinor($this->payableOwed())->format().', the live bills owe '.Money::ofMinor($this->billsOwe())->format().'.';
        }

        $ledger = SupplierLedger::for($this->d->supplier, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'));

        if (! $ledger->isConsistent()) {
            $problems[] = 'The supplier ledger view does not reconcile per account.';
        }

        if ($ledger->billedPayable()->minor !== $this->payableOwed()) {
            $problems[] = 'The supplier ledger view shows a different billed payable.';
        }

        return $problems;
    }

    public function journalCount(): int
    {
        return DB::table('journals')->count();
    }

    public function journalOf(Model $document): ?int
    {
        return app(PostPurchaseJournals::class)->existingJournal($document)?->id;
    }
}
