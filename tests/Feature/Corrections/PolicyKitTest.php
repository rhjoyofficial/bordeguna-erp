<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Corrections\InterBusinessTransferActionPolicy;
use App\Modules\Accounting\Corrections\ManualJournalActionPolicy;
use App\Modules\Accounting\Corrections\MoneyTransferActionPolicy;
use App\Modules\Accounting\Corrections\PartyAdjustmentActionPolicy;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Accounting\Corrections\VoucherActionPolicy;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Actions\DisposeFixedAsset;
use App\Modules\Assets\Corrections\FixedAssetActionPolicy;
use App\Modules\Crm\Actions\LogCrmActivity;
use App\Modules\Crm\Corrections\ContactActionPolicy;
use App\Modules\Crm\Corrections\CrmActivityActionPolicy;
use App\Modules\Crm\Corrections\LeadActionPolicy;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Hrm\Actions\CancelPayrollAdjustment;
use App\Modules\Hrm\Actions\CancelStaffLoan;
use App\Modules\Hrm\Actions\ReverseExpenseClaimPayment;
use App\Modules\Hrm\Corrections\ExpenseClaimActionPolicy;
use App\Modules\Hrm\Corrections\PayrollAdjustmentActionPolicy;
use App\Modules\Hrm\Corrections\StaffLoanActionPolicy;
use App\Modules\Inventory\Actions\ApproveCropWriteOff;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\RaiseCropWriteOff;
use App\Modules\Inventory\Actions\RecordCropHarvest;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\WithdrawInventoryAdjustment;
use App\Modules\Inventory\Corrections\CropHarvestActionPolicy;
use App\Modules\Inventory\Corrections\CropWriteOffActionPolicy;
use App\Modules\Inventory\Corrections\InventoryAdjustmentActionPolicy;
use App\Modules\Inventory\Corrections\OpeningStockActionPolicy;
use App\Modules\Inventory\Corrections\ProductionRunActionPolicy;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\database\seeders\CropWriteOffWorkflowSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Support\ProductionRunForm;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierBillActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierPaymentActionPolicy;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\DeliveryActionPolicy;
use App\Modules\Sales\Corrections\ExtraChargePolicy;
use App\Modules\Sales\Corrections\PriceCreditPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountsWorld;
use Tests\Support\CorrectionWorld;
use Tests\Support\DiscountWorld;
use Tests\Support\HrmKit;
use Tests\Support\PartyWorld;
use Tests\Support\PeopleWorld;
use Tests\Support\ReceiptWorld;
use Tests\Support\SalesWorld;
use Tests\Support\StockWorld;
use Tests\Support\SupplierMoneyWorld;

/**
 * The policy test kit (PHASE-0-CONTRACT v2, 1.2 and 3.7 test 40; ChatGPT's 49).
 * Every registered DocumentActionPolicy is walked against a real record of its
 * type, in each state the slice can reach. A module adds its type to the
 * `records` map below when it registers a policy.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * ⭐ Corrections wave 2, accounts (B7, 24 Oct 2026): a voucher live, reversed
 * and bank-matched; a transfer live and reversed; a manual journal; an
 * adjustment.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function accountsKitRecords(CorrectionWorld $w): array
{
    static $built = [];
    $key = spl_object_id($w);

    if (isset($built[$key])) {
        return $built[$key];
    }

    $a = AccountsWorld::on($w);
    $voucher = $a->payment('5000');
    $reversed = $a->payment('700', '2026-10-14');
    $a->submit(VoucherActionPolicy::TYPE, $reversed, DocumentAction::Reverse, $w->financeB);
    $matched = $a->payment('900', '2026-10-13');
    $a->matchJournalLine((int) $matched->journal_id);
    $transfer = $a->transfer('300');
    $gone = $a->transfer('400', '2026-10-14');
    $a->submit(MoneyTransferActionPolicy::TYPE, $gone, DocumentAction::Reverse, $w->owner);
    $journal = Journal::query()->whereNull('source_type')->first() ?? app(PostJournal::class)->handle(new JournalDraft(
        Carbon::parse('2026-10-10'),
        [JournalLineDraft::debit($a->expense->id, Money::ofTaka('100')), JournalLineDraft::credit(Account::bySlug('cash_in_hand')->id, Money::ofTaka('100'))],
        'Kit journal',
    ), $w->owner);

    return $built[$key] = [
        VoucherActionPolicy::TYPE => [[$voucher->fresh(), 'live'], [$reversed->fresh(), 'reversed'], [$matched->fresh(), 'bank-matched']],
        MoneyTransferActionPolicy::TYPE => [[$transfer->fresh(), 'live'], [$gone->fresh(), 'reversed']],
        ManualJournalActionPolicy::TYPE => [[$journal->fresh(), 'live'], [Journal::find($voucher->journal_id), 'a voucher journal']],
        InterBusinessTransferActionPolicy::TYPE => [[$a->interBusinessTransfer()->fresh(), 'live']],
        PartyAdjustmentActionPolicy::TYPE => [[$a->writeOff()[1]->fresh(), 'live']],
    ];
}

/** @return list<array{0: Model, 1: string}> */
function kitRecords(CorrectionWorld $w): array
{
    $live = $w->record('5000', '2026-10-15');
    $reversed = $w->record('700', '2026-10-15');
    $w->reverse($reversed, $w->financeB);
    $matched = $w->record('900', '2026-10-15');
    $w->matchOnBankStatement($matched);

    return [[$live, 'live'], [$reversed->fresh(), 'reversed'], [$matched, 'bank-matched']];
}

/**
 * ⭐ Set-offs (D-F, 18 Oct 2026): waiting, posted and reversed. Built in their
 * own world (PartyWorld), whose users the four rules hold for just the same.
 *
 * @return list<array{0: Model, 1: string}>
 */
function setOffKitRecords(CorrectionWorld $w): array
{
    $p = PartyWorld::make($w);
    $bill = $p->bill('5000');
    $invoice = $p->invoice('5000');
    $pending = $p->raise([$bill->id => '100'], [$invoice->id => '100']);
    $posted = $p->posted([$bill->id => '200'], [$invoice->id => '200']);
    $reversed = $p->posted([$bill->id => '300'], [$invoice->id => '300']);
    $p->reverse($reversed, $p->w->financeB);

    return [[$pending->fresh(), 'pending'], [$posted->fresh(), 'posted'], [$reversed->fresh(), 'reversed']];
}

/**
 * ⭐ Supplier discounts (ruling P3, 18 Oct 2026): live, reversed, and live
 * with its batch moved since (Reverse blocked by the stock check).
 *
 * @return list<array{0: Model, 1: string}>
 */
function supplierDiscountKitRecords(CorrectionWorld $w): array
{
    $d = DiscountWorld::make($w);
    $live = $d->bill($d->order([[$d->salt, '25', '20.00']]), ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-K1'], 'INV-K1');
    $reversed = $d->bill($d->order([[$d->sugar, '10', '10.00']]), ['mode' => 'amount', 'value' => '10', 'reference' => 'INV-K2'], 'INV-K2');
    $d->reverse(SupplierDiscount::where('supplier_bill_id', $reversed->id)->sole(), $w->financeB);
    app(RecordStockOut::class)->handle(product: $d->salt, warehouse: $d->warehouse, quantity: '1', performedBy: $w->owner, occurredAt: '2026-10-16');
    $moved = $d->bill($d->order([[$d->salt, '5', '20.00']]), ['mode' => 'amount', 'value' => '5', 'reference' => 'INV-K3'], 'INV-K3');
    app(RecordStockOut::class)->handle(product: $d->salt, warehouse: $d->warehouse, quantity: '25', performedBy: $w->owner, occurredAt: '2026-10-17');

    return [
        [SupplierDiscount::where('supplier_bill_id', $live->id)->sole(), 'live'],
        [SupplierDiscount::where('supplier_bill_id', $reversed->id)->sole(), 'reversed'],
        [SupplierDiscount::where('supplier_bill_id', $moved->id)->sole(), 'stock-moved'],
    ];
}

/**
 * ⭐ Corrections wave 1, purchase documents (19 Oct 2026): goods receipts
 * live, used (Reverse blocked), billed, reversed; purchase orders waiting,
 * approved, received, cancelled.
 *
 * @return list<array{0: Model, 1: string}>
 */
function purchaseDocumentKitRecords(CorrectionWorld $w, string $type): array
{
    $r = ReceiptWorld::fromCorrectionWorld($w);

    if ($type === GoodsReceiptActionPolicy::TYPE) {
        $live = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-10'));
        $usedOrder = $r->received([[$r->d->sugar, '5', '20.00']], '2026-10-10');
        app(RecordStockOut::class)->handle(product: $r->d->sugar, warehouse: $r->d->warehouse, quantity: '1', performedBy: $w->owner, occurredAt: '2026-10-11');
        $billedOrder = $r->received([[$r->d->salt, '3', '20.00']], '2026-10-10');
        $r->d->bill($billedOrder, null, 'INV-KIT');
        $reversed = $r->receiptOf($r->received([[$r->d->salt, '2', '20.00']], '2026-10-10'));
        $r->reverse($reversed, $w->owner);

        return [[$live, 'live'], [$r->receiptOf($usedOrder), 'used'], [$r->receiptOf($billedOrder), 'billed'], [$reversed->fresh(), 'reversed']];
    }

    $waiting = $r->approved([[$r->d->salt, '5', '20.00']], $r->buyer);
    $approved = $r->approved([[$r->d->salt, '5', '20.00']]);
    $received = $r->received([[$r->d->salt, '5', '20.00']], '2026-10-10');
    $cancelled = $r->approved([[$r->d->salt, '5', '20.00']]);
    app(CancelPurchaseOrder::class)->handle($cancelled, $w->owner);

    return [[$waiting, 'waiting'], [$approved, 'approved'], [$received, 'received'], [$cancelled->fresh(), 'cancelled']];
}

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): supplier bills, payments, credit
 * notes and purchase returns, each live, reversed, and blocked by a dependency.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function supplierMoneyKitRecords(CorrectionWorld $w): array
{
    static $built = [];
    $key = spl_object_id($w);

    if (isset($built[$key])) {
        return $built[$key];
    }

    $m = SupplierMoneyWorld::make($w);
    $order = $m->d->order([[$m->d->salt, '50', '20.00']]);
    $live = $m->d->bill($order, null, 'INV-K10');
    $paid = $m->d->bill($m->d->order([[$m->d->sugar, '10', '10.00']]), null, 'INV-K11');
    $payment = $m->d->pay($paid, '50');
    $reversedBill = $m->d->bill($m->d->order([[$m->d->sugar, '5', '10.00']]), null, 'INV-K12');
    $m->reverse($reversedBill, $w->financeB);
    $reversedPayment = $m->d->pay($live, '100');
    $m->reverse($reversedPayment, $w->financeB);
    $return = $m->returnGoods($order, '5');
    $note = $m->creditNote($return, $live, '50');
    $freeReturn = $m->returnGoods($order, '5');
    $m->reverse($freeReturn, $w->financeB);
    $reversedNote = $m->creditNote($m->returnGoods($order, '2'), $live, '20', 'CN-K2');
    $m->reverse($reversedNote, $w->financeB);

    return $built[$key] = [
        SupplierBillActionPolicy::TYPE => [[$live->fresh(), 'live'], [$paid->fresh(), 'paid'], [$reversedBill->fresh(), 'reversed']],
        SupplierPaymentActionPolicy::TYPE => [[$payment->fresh(), 'live'], [$reversedPayment->fresh(), 'reversed']],
        SupplierCreditNoteActionPolicy::TYPE => [[$note->fresh(), 'live'], [$reversedNote->fresh(), 'reversed']],
        PurchaseReturnActionPolicy::TYPE => [[$return->fresh(), 'credited'], [$freeReturn->fresh(), 'reversed']],
    ];
}

/**
 * ⭐ Stock documents (corrections wave 1, B4, 20 Oct 2026): adjustments
 * (waiting, withdrawn, approved, reversed), opening stock (live, corrected),
 * transfers (on the road, cancelled), production runs (draft, confirmed with
 * its output sold, reversed). Built once and shared by the four types.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function stockKitRecords(CorrectionWorld $w): array
{
    // A WeakMap, so a later test's world never reads this one's records.
    static $built = null;
    $built ??= new WeakMap;

    if (isset($built[$w])) {
        return $built[$w];
    }

    $s = StockWorld::make($w, '-K');
    $s->noSelfApproval();
    $opening = $s->opening([[$s->honey, '10', '500', 'K-01'], [$s->salt, '100', '60'], [$s->ghee, '20', '900']]);
    $approve = fn ($a) => app(ApproveInventoryAdjustment::class)->handle($a->fresh(), $w->manager, 'Seen it');

    $waiting = $s->adjust('Damaged', AdjustmentDirection::Decrease, $s->honey, '1', $s->staff);
    $withdrawn = $s->adjust('Damaged', AdjustmentDirection::Decrease, $s->honey, '1', $s->staff);
    app(WithdrawInventoryAdjustment::class)->handle($withdrawn, $s->staff, new ActionReason(null, 'Wrong jar counted'));
    $approved = $s->adjust('Damaged', AdjustmentDirection::Decrease, $s->honey, '1', $s->staff);
    $approve($approved);
    $reversed = $s->adjust('Damaged', AdjustmentDirection::Decrease, $s->honey, '1', $s->staff);
    $approve($reversed);
    $s->submit($s->input(InventoryAdjustmentActionPolicy::TYPE, $reversed, DocumentAction::Reverse), $w->manager);

    $correctedOpening = $s->opening([[$s->salt, '5', '60']], $s->shop);
    $s->submit($s->input(OpeningStockActionPolicy::TYPE, $correctedOpening, DocumentAction::Correct, [
        'note' => null,
        'lines' => [['line_id' => $correctedOpening->lines->first()->id, 'product_id' => $s->salt->id, 'quantity' => '6', 'unit_cost' => '60', 'batch_number' => $correctedOpening->lines->first()->batch_number, 'expiry_date' => null]],
    ]), $w->manager);

    $send = fn () => app(DispatchStockTransfer::class)->handle(product: $s->ghee, source: $s->savar, target: $s->shop, quantity: '1',
        reason: $s->reason('Warehouse rebalancing', 'inventory.stock_transfer'), performedBy: $w->manager, occurredAt: '2026-10-10');
    $onTheRoad = $send();
    $cancelled = $send();
    app(CancelStockTransfer::class)->handle($cancelled, $w->manager, new ActionReason(null, 'Van did not go'));

    $form = fn (string $made, string $salt) => [
        'warehouse_id' => $s->savar->id, 'output_product_id' => $s->ghee->id, 'output_quantity' => $made, 'occurred_at' => '2026-10-08',
        'expense_waste' => false, 'note' => null, 'costs' => [],
        'lines' => [['product_id' => $s->salt->id, 'quantity' => $salt, 'waste' => '0', 'unit_id' => null]],
    ];
    $record = app(RecordProductionRun::class);
    $draft = $record->draft(...ProductionRunForm::arguments($form('2', '1')), createdBy: $w->manager);
    $confirmed = $record->confirm($record->draft(...ProductionRunForm::arguments($form('2', '1')), createdBy: $w->manager), $w->manager);
    $reversedRun = $record->confirm($record->draft(...ProductionRunForm::arguments($form('2', '1')), createdBy: $w->manager), $w->manager);
    $s->submit($s->input(ProductionRunActionPolicy::TYPE, $reversedRun, DocumentAction::Reverse), $w->manager);

    // ⭐ Farm harvest (24 Oct 2026): a live and a reversed harvest; a waiting,
    // an approved, a rejected and a reversed crop write-off.
    (new CropWriteOffWorkflowSeeder)->run();
    $crop = Account::create(['code' => '1152-K', 'name' => 'Crops in Progress (kit)', 'type' => AccountType::Asset, 'parent_id' => Account::where('code', '1100')->value('id'), 'is_postable' => true]);
    $loss = Account::create(['code' => '5210-K', 'name' => 'Crop Failure Loss (kit)', 'type' => AccountType::Expense, 'parent_id' => Account::where('code', '5000')->value('id'), 'is_postable' => true]);
    app(PostJournal::class)->handle(new JournalDraft(date: Carbon::parse('2026-10-04'), lines: [
        JournalLineDraft::debit($crop->id, Money::ofTaka('9000')),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('9000')),
    ], memo: 'Kit season'), $w->owner);
    $harvest = fn () => app(RecordCropHarvest::class)->handle('2026-10-08', $crop, $s->savar, [['product' => $s->salt, 'quantity' => '10']], $w->manager, '1000');
    $liveHarvest = $harvest();
    $reversedHarvest = $harvest();
    $s->submit($s->input(CropHarvestActionPolicy::TYPE, $reversedHarvest, DocumentAction::Reverse), $w->manager);
    $raise = fn () => app(RaiseCropWriteOff::class)->handle('2026-10-08', $crop, $loss, '500', 'Flooded plot', $w->manager);
    $waitingWriteOff = $raise();
    $approvedWriteOff = app(ApproveCropWriteOff::class)->handle($raise(), $w->owner);
    $rejectedWriteOff = app(ApproveCropWriteOff::class)->reject($raise(), $w->owner, 'No', app(RejectApprovable::class));
    $reversedWriteOff = app(ApproveCropWriteOff::class)->handle($raise(), $w->owner);
    $s->submit($s->input(CropWriteOffActionPolicy::TYPE, $reversedWriteOff, DocumentAction::Reverse), $w->manager);

    return $built[$w] = [
        CropHarvestActionPolicy::TYPE => [[$liveHarvest->fresh(), 'live'], [$reversedHarvest->fresh(), 'reversed']],
        CropWriteOffActionPolicy::TYPE => [[$waitingWriteOff->fresh(), 'waiting'], [$approvedWriteOff->fresh(), 'approved'], [$rejectedWriteOff->fresh(), 'rejected'], [$reversedWriteOff->fresh(), 'reversed']],
        InventoryAdjustmentActionPolicy::TYPE => [[$waiting->fresh(), 'waiting'], [$withdrawn->fresh(), 'withdrawn'], [$approved->fresh(), 'approved'], [$reversed->fresh(), 'reversed']],
        OpeningStockActionPolicy::TYPE => [[$opening->fresh(), 'live'], [$correctedOpening->fresh(), 'corrected']],
        StockTransferActionPolicy::TYPE => [[$onTheRoad->fresh(), 'on the road'], [$cancelled->fresh(), 'cancelled']],
        ProductionRunActionPolicy::TYPE => [[$draft->fresh(), 'draft'], [$confirmed->fresh(), 'confirmed'], [$reversedRun->fresh(), 'reversed']],
    ];
}

/**
 * ⭐ Sales documents (corrections wave 1, 20 Oct 2026): one SalesWorld for the
 * three types, built once per CorrectionWorld. Invoices live, credited and a
 * counter sale; receipts live and reversed; challans live, billed and reversed.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function salesKitRecords(CorrectionWorld $w): array
{
    static $built = [];
    $key = spl_object_id($w);

    if (isset($built[$key])) {
        return $built[$key];
    }

    $s = SalesWorld::make($w);
    $billed = $s->challan(null, '5');
    $invoice = $s->invoice($billed);
    $credited = $s->invoice($s->challan(null, '3'), '850');
    $s->submit($s->input(SalesInvoiceActionPolicy::TYPE, $credited, DocumentAction::Reverse), $w->financeB);
    $counter = $s->counterSale('1');
    $receipt = $s->receive($invoice, '1000');
    $gone = $s->receive($invoice, '500');
    $s->submit($s->input(CustomerReceiptActionPolicy::TYPE, $gone, DocumentAction::Reverse), $w->financeB);
    $loose = $s->challan(null, '2');
    $reversed = $s->challan(null, '2');
    $s->submit($s->input(DeliveryActionPolicy::TYPE, $reversed, DocumentAction::Reverse), $w->manager);

    return $built[$key] = [
        SalesInvoiceActionPolicy::TYPE => [[$invoice->fresh(), 'live'], [$credited->fresh(), 'credited'], [$counter->invoice->fresh(), 'counter sale']],
        CustomerReceiptActionPolicy::TYPE => [[$receipt->fresh(), 'live'], [$gone->fresh(), 'reversed']],
        DeliveryActionPolicy::TYPE => [[$loose->fresh(), 'live'], [$billed->fresh(), 'billed'], [$reversed->fresh(), 'reversed']],
    ];
}

/**
 * ⭐ People and asset documents (corrections wave 2, B8, 24 Oct 2026): claims
 * approved, paid and payment-reversed; adjustments approved and cancelled;
 * loans paid out and cancelled; fixed assets held, disposed and reversed.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function peopleKitRecords(CorrectionWorld $w): array
{
    static $built = null;
    $built ??= new WeakMap;

    if (isset($built[$w])) {
        return $built[$w];
    }

    $p = PeopleWorld::make($w);
    $approved = $p->approvedClaim('300');
    $paid = $p->paidNow($p->approvedClaim('400'));
    $unpaid = $p->paidNow($p->approvedClaim('500'));
    app(ReverseExpenseClaimPayment::class)->handle($unpaid, $w->owner, $p->reason());

    $adjustment = $p->approvedAdjustment($p->period(2026, 11));
    $cancelledAdjustment = $p->approvedAdjustment($p->period(2026, 11));
    app(CancelPayrollAdjustment::class)->handle($cancelledAdjustment, $w->financeB, $p->reason());

    $loan = $p->disbursedLoan();
    $cancelledLoan = $p->disbursedLoan();
    app(CancelStaffLoan::class)->handle($cancelledLoan, $w->owner, $p->reason());

    $held = $p->asset('15000', '2026-07-15', 'FA-K1');
    $disposed = $p->asset('15000', '2026-07-15', 'FA-K2');
    app(DisposeFixedAsset::class)->handle($disposed, '2026-10-16', 'donated', '0', null, Account::where('code', '6011')->first() ?? Account::query()->where('type', 'expense')->where('is_postable', true)->firstOrFail(), $w->owner, $p->reason());
    $reversed = $p->asset('15000', '2026-07-15', 'FA-K3');
    reverseKitAsset($reversed, $w);

    return $built[$w] = [
        ExpenseClaimActionPolicy::TYPE => [[$approved->fresh(), 'approved'], [$paid->fresh(), 'paid'], [$unpaid->fresh(), 'payment reversed']],
        PayrollAdjustmentActionPolicy::TYPE => [[$adjustment->fresh(), 'approved'], [$cancelledAdjustment->fresh(), 'cancelled']],
        StaffLoanActionPolicy::TYPE => [[$loan->fresh(), 'paid out'], [$cancelledLoan->fresh(), 'cancelled']],
        FixedAssetActionPolicy::TYPE => [[$held->fresh(), 'held'], [$disposed->fresh(), 'disposed'], [$reversed->fresh(), 'reversed']],
    ];
}

function reverseKitAsset(Model $asset, CorrectionWorld $w): void
{
    $input = new CorrectionInput(
        type: FixedAssetActionPolicy::TYPE, documentId: $asset->getKey(), action: DocumentAction::Reverse,
        reason: new ActionReason(null, 'Entered by mistake'), replacement: null,
        dateChoice: ReverseDateChoice::OriginalDate, idempotencyKey: (string) Str::uuid(),
    );
    $service = app(CorrectionService::class);
    $service->submit($input, $w->owner, $service->preview($input, $w->owner)->previewToken);
}

/**
 * ⭐ CRM (corrections wave 3, 24 Oct 2026): a working lead, a converted one, a
 * fresh and an old call, a corrected one, and a contact with and without a
 * message sent. Built once per world, like the stock records.
 *
 * @return array<string, list<array{0: Model, 1: string}>>
 */
function crmKitRecords(CorrectionWorld $w): array
{
    static $built = null;

    if ($built !== null && $built['world'] === $w) {
        return $built['records'];
    }

    $working = Lead::create(['business_id' => $w->business->id, 'name' => 'Kit Shop', 'phone' => '01711999001', 'status' => LeadStatus::Contacted]);
    $customer = Customer::create(['business_id' => $w->business->id, 'code' => 'KIT-C', 'name' => 'Kit Customer', 'is_active' => true]);
    $converted = Lead::create(['business_id' => $w->business->id, 'name' => 'Kit Converted', 'phone' => '01711999002', 'status' => LeadStatus::Qualified]);
    $converted->forceFill(['converted_customer_id' => $customer->id, 'converted_at' => now(), 'status' => LeadStatus::Converted])->save();

    $fresh = app(LogCrmActivity::class)->handle($working, ActivityType::Call, 'Kit call', $w->owner, now()->subHour());
    $old = app(LogCrmActivity::class)->handle($working, ActivityType::Note, 'Kit note', $w->owner, now()->subDays(3));
    $old->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
    $corrected = app(LogCrmActivity::class)->handle($working, ActivityType::Visit, 'Kit visit', $w->owner, now()->subDays(3));
    CrmActivity::unlocked(fn () => $corrected->forceFill(['created_at' => now()->subDays(3), 'corrected_at' => now(), 'corrected_by' => $w->owner->id])->save());

    $contact = Contact::create(['business_id' => $w->business->id, 'name' => 'Kit Contact', 'phone' => '01711999003']);
    $messaged = Contact::create(['business_id' => $w->business->id, 'name' => 'Kit Messaged', 'phone' => '01711999004']);
    DB::table('message_deliveries')->insert([
        'business_id' => $w->business->id,
        'message_campaign_id' => DB::table('message_campaigns')->insertGetId(['business_id' => $w->business->id, 'channel' => 'sms', 'name' => 'Kit', 'body' => 'Kit', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]),
        'contact_id' => $messaged->id, 'to_address' => '01711999004', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $records = [
        LeadActionPolicy::TYPE => [[$working, 'working'], [$converted->fresh(), 'converted']],
        CrmActivityActionPolicy::TYPE => [[$fresh, 'fresh'], [$old->fresh(), 'older than a day'], [$corrected->fresh(), 'corrected']],
        ContactActionPolicy::TYPE => [[$contact, 'never messaged'], [$messaged, 'messaged']],
    ];
    $built = ['world' => $w, 'records' => $records];

    return $records;
}

it('40. every registered policy follows the four rules for every user and every record state', function () {
    $registry = app(DocumentPolicyRegistry::class);
    $users = [$this->w->owner, $this->w->financeB, $this->w->manager, $this->w->sales];
    $problems = [];

    foreach ($registry->types() as $type) {
        $policy = $registry->for($type);
        $records = match ($type) {
            IncomeVoucherActionPolicy::TYPE => kitRecords($this->w),
            SetOffActionPolicy::TYPE => setOffKitRecords($this->w),
            SupplierDiscountActionPolicy::TYPE => supplierDiscountKitRecords($this->w),
            GoodsReceiptActionPolicy::TYPE, PurchaseOrderActionPolicy::TYPE => purchaseDocumentKitRecords($this->w, $type),
            SupplierBillActionPolicy::TYPE, SupplierPaymentActionPolicy::TYPE, SupplierCreditNoteActionPolicy::TYPE, PurchaseReturnActionPolicy::TYPE => supplierMoneyKitRecords($this->w)[$type],
            InventoryAdjustmentActionPolicy::TYPE, OpeningStockActionPolicy::TYPE, StockTransferActionPolicy::TYPE, ProductionRunActionPolicy::TYPE,
            CropHarvestActionPolicy::TYPE, CropWriteOffActionPolicy::TYPE => stockKitRecords($this->w)[$type],
            SalesInvoiceActionPolicy::TYPE, CustomerReceiptActionPolicy::TYPE, DeliveryActionPolicy::TYPE => salesKitRecords($this->w)[$type],
            // ⭐ 24 Oct 2026: a price credit and an extra charge act on invoices.
            PriceCreditPolicy::TYPE, ExtraChargePolicy::TYPE => salesKitRecords($this->w)[SalesInvoiceActionPolicy::TYPE],
            ExpenseClaimActionPolicy::TYPE, PayrollAdjustmentActionPolicy::TYPE, StaffLoanActionPolicy::TYPE, FixedAssetActionPolicy::TYPE => peopleKitRecords($this->w)[$type],
            VoucherActionPolicy::TYPE, MoneyTransferActionPolicy::TYPE, ManualJournalActionPolicy::TYPE,
            InterBusinessTransferActionPolicy::TYPE, PartyAdjustmentActionPolicy::TYPE => accountsKitRecords($this->w)[$type],
            // Corrections wave 3 (24 Oct 2026): the HR records.
            'hrm.leave_request', 'hrm.movement', 'hrm.employee', 'hrm.show_cause_notice' => ($hrm ??= HrmKit::records($this->w))[$type],
            LeadActionPolicy::TYPE, CrmActivityActionPolicy::TYPE, ContactActionPolicy::TYPE => crmKitRecords($this->w)[$type],
            default => [],
        };

        expect($records)->not->toBeEmpty("The policy kit has no record for {$type}: add one.");

        foreach ($records as [$record, $state]) {
            foreach ($users as $user) {
                $set = $policy->actionsFor($record, $user);
                $primary = 0;

                foreach ($set->actions as $availability) {
                    $where = "{$type} {$state} for {$user->name}: {$availability->action->value}";

                    // 1. Edit is never offered on a Done record.
                    if ($set->stage === DocumentStage::Done && $availability->action === DocumentAction::Edit) {
                        $problems[] = "{$where}: Edit offered on a Done record";
                    }

                    // 2. Delete is never offered when the type has an effect.
                    if ($availability->action === DocumentAction::Delete && $policy->effectProfile()->effects !== []) {
                        $problems[] = "{$where}: Delete offered on a type with effects";
                    }

                    // 3. A blocked action keeps its place, with its blockers.
                    if (! $availability->allowed && $availability->blockers === []) {
                        $problems[] = "{$where}: blocked with no reason";
                    }

                    // 4. Every blocker is one plain sentence.
                    foreach ($availability->blockers as $blocker) {
                        if (! preg_match('/^[A-Z0-9].*[.]$/s', $blocker->message) || preg_match('/[A-Z][a-z]+\\\\|::|_[a-z]+_|\bnull\b/', $blocker->message) || substr_count(rtrim($blocker->message, '.'), '. ') > 1) {
                            $problems[] = "{$where}: blocker is not one plain sentence: {$blocker->message}";
                        }
                    }

                    $primary += $availability->primary ? 1 : 0;
                }

                if ($primary > 2) {
                    $problems[] = "{$type} {$state} for {$user->name}: more than two primary buttons";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('49. an effect with no check covering it fails closed', function () {
    $profile = new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Stock], [BankMatchBlocker::class]);

    expect($profile->uncoveredEffects())->toBe([EffectKind::Ledger, EffectKind::Stock]);

    // A policy that declares Stock without a stock check blocks every money action.
    $policy = new class(app(CorrectionDatePolicy::class), app(SecondPersonRule::class)) extends IncomeVoucherActionPolicy
    {
        public function effectProfile(): EffectProfile
        {
            return new EffectProfile([EffectKind::Ledger, EffectKind::Stock], [LedgerEntryCheck::class]);
        }
    };

    $voucher = $this->w->record();
    $availability = $policy->availability($voucher, $this->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('missing_dependency_check');

    // The real Other income profile is fully covered.
    expect(app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE)->effectProfile()->uncoveredEffects())->toBe([]);
});
