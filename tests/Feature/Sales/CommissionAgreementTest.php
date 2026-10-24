<?php

use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Inventory\Models\Category;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\Commission\PayCommission;
use App\Modules\Sales\Actions\Commission\SaveCommissionAgreement;
use App\Modules\Sales\Actions\Commission\SettleCommission;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Corrections\CommissionPaymentActionPolicy;
use App\Modules\Sales\Corrections\CommissionSettlementActionPolicy;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\CommissionTracker;
use App\Support\ApprovalCards;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesWorld;

/**
 * ⭐ Commission agreements, the engine (Joy's Q18, 25 Oct 2026): "a commission
 * system needs cause someone can offer i can sell these amount or i will sell
 * but per product you give me these amount, we can agree about that."
 *
 * Nobody earns anything without an agreement naming them; only the sales the
 * agreement names count; settling posts through PostJournal (or the next
 * payroll); above the Finance policy limit a second person approves.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;
    (new VoucherWorkflowSeeder)->run();
    $this->w->enter($this->w->business);

    $this->agent = Supplier::create(['business_id' => $this->w->business->id, 'name' => 'Belal Agent', 'is_active' => true]);
    $this->employee = Employee::create([
        'business_id' => $this->w->business->id, 'employee_code' => 'BDG-000077',
        'first_name' => 'Shoykot', 'last_name' => 'Ali', 'joining_date' => '2026-01-01',
        'status' => 'active', 'employment_type' => 'permanent',
    ]);
});

function commissionAgreement(array $overrides = []): CommissionAgreement
{
    $t = test();

    return app(SaveCommissionAgreement::class)->create(array_merge([
        'agreed_on' => '2026-10-01', 'party_type' => 'supplier', 'party_id' => $t->agent->id,
        'valid_from' => '2026-10-01', 'valid_to' => null, 'deal_type' => 'per_unit', 'rate' => '20',
        'counts_credited_sales' => true, 'counts_own_purchases' => false,
        'product_ids' => [$t->s->honey->id], 'category_ids' => [], 'tiers' => [],
    ], $overrides), $t->w->owner);
}

/** Order, challan and invoice of honey; the agent on the ORDER only. */
function creditedSale(string $quantity, string $date, ?CommissionAgreement $for = null, string $price = '850'): SalesInvoice
{
    $t = test();
    $order = $t->s->order($quantity, $price, date: $date);

    if ($for !== null) {
        $order->forceFill(['agent_type' => $for->party_type, 'agent_id' => $for->partyId()])->save();
    }

    return $t->s->invoice($t->s->challan($order, $quantity, $date), $price, $date);
}

it('counts only the sales credited to the person, by the order or the invoice, and earns per unit', function () {
    $deal = commissionAgreement();

    creditedSale('4', '2026-10-05', $deal);
    // Not credited to anybody: does not count.
    creditedSale('3', '2026-10-06');
    // Credited on the invoice itself (a counter bill or a direct invoice).
    $direct = creditedSale('2', '2026-10-07');
    $direct->forceFill(['agent_type' => 'supplier', 'agent_id' => $this->agent->id])->save();
    $this->s->today('2026-10-17 10:00:00');

    $progress = app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']));

    expect((float) $progress['sold_quantity'])->toBe(6.0)
        ->and((float) $progress['sold_value'])->toBe(5100.0)
        ->and($progress['earned'])->toBe('120.00')
        ->and($progress['to_settle'])->toBe('120.00');
});

it('takes returns off what was sold and pays a percent on the value net of the invoice discount', function () {
    $deal = commissionAgreement(['deal_type' => 'percent', 'rate' => '5']);
    $invoice = creditedSale('10', '2026-10-05', $deal);
    $invoice->forceFill(['invoice_discount' => '850'])->save(); // 10% off 8,500

    $delivery = $invoice->lines()->first()->deliveryLine->delivery->fresh('lines');
    app(RecordSalesReturn::class)->handle(
        delivery: $delivery,
        lines: [['deliveryLine' => $delivery->lines->first(), 'quantityToStock' => '2', 'quantityWrittenOff' => '0']],
        createdBy: $this->w->owner,
        returnedAt: '2026-10-08',
    );

    $progress = app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']));

    // 8 kept x 850 x 90% = 6,120; 5% = 306.
    expect((float) $progress['sold_quantity'])->toBe(8.0)
        ->and((float) $progress['sold_value'])->toBe(6120.0)
        ->and($progress['earned'])->toBe('306.00');
});

it('pays the highest target step reached, and nothing below the first', function () {
    $deal = commissionAgreement([
        'deal_type' => 'target', 'target_basis' => 'units', 'valid_to' => '2026-10-31', 'rate' => null,
        'tiers' => [['threshold' => '5', 'reward' => '500'], ['threshold' => '10', 'reward' => '1500']],
    ]);

    creditedSale('4', '2026-10-05', $deal);
    expect(app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']))['earned'])->toBe('0.00');

    creditedSale('7', '2026-10-06', $deal);
    $progress = app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']));

    expect($progress['earned'])->toBe('1500.00')
        ->and($progress['target_percent'])->toBe(110.0);
});

it('counts a whole category, and a customer agreement can count their own purchases', function () {
    $category = Category::create(['business_id' => $this->w->business->id, 'name' => 'Honey', 'slug' => 'honey']);
    $this->s->honey->forceFill(['category_id' => $category->id])->save();

    $deal = commissionAgreement([
        'party_type' => 'customer', 'party_id' => $this->s->customer->id,
        'counts_credited_sales' => false, 'counts_own_purchases' => true,
        'product_ids' => [], 'category_ids' => [$category->id], 'rate' => '10',
    ]);

    creditedSale('3', '2026-10-05');

    expect(app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']))['earned'])->toBe('30.00');
});

it('settles what was earned as owed: Dr Sales Commission / Cr Commission Payable for the person, then pays it', function () {
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);

    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner);

    expect($settlement->status)->toBe(CommissionSettlement::POSTED)
        ->and($settlement->amount)->toBe('100.0000');

    $journal = Journal::query()->whereKey($settlement->journal_id)->with('lines')->sole();
    $expense = Account::bySlug('sales_commission');
    $payable = Account::bySlug('commission_payable');

    expect((int) $journal->lines->sum('amount_minor'))->toBe(0)
        ->and((int) $journal->lines->firstWhere('account_id', $expense->id)->amount_minor)->toBe(10000)
        ->and((int) $journal->lines->firstWhere('account_id', $payable->id)->amount_minor)->toBe(-10000)
        ->and($journal->lines->firstWhere('account_id', $payable->id)->party_id)->toBe($this->agent->id);

    // A second settle through the same day finds nothing new.
    expect(fn () => app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner))
        ->toThrow(ValidationException::class);

    $payment = app(PayCommission::class)->handle($settlement, '2026-10-16', '60', $this->w->cash, null, $this->w->owner);
    expect($settlement->fresh()->owed()->toTaka())->toBe('40.00')
        ->and($this->w->balance($payable))->toBe(-4000);

    expect(fn () => app(PayCommission::class)->handle($settlement, '2026-10-16', '41', $this->w->cash, null, $this->w->owner))
        ->toThrow(ValidationException::class, 'Only Tk 40.00 is still owed');
    expect($payment->journal_id)->not->toBeNull();
});

it('settles only the difference since the last settlement, and pays now from a money account', function () {
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    app(SettleCommission::class)->handle($deal, '2026-10-10', '2026-10-10', 'paid_now', $this->w->cash, null, $this->w->owner);

    creditedSale('2', '2026-10-12', $deal);
    $second = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'paid_now', $this->w->cash, null, $this->w->owner);

    expect($second->amount)->toBe('40.0000')
        ->and($second->from_date->toDateString())->toBe('2026-10-11')
        ->and((float) $second->sold_quantity)->toBe(2.0)
        ->and($this->w->balance(Account::bySlug('sales_commission')))->toBe(14000);
});

it('puts an employee settlement on the next open payroll as an addition, posting nothing now', function () {
    $deal = commissionAgreement(['party_type' => 'employee', 'party_id' => $this->employee->id]);
    creditedSale('3', '2026-10-05', $deal);

    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payroll', null, null, $this->w->owner);

    $adjustment = PayrollAdjustment::query()->whereKey($settlement->payroll_adjustment_id)->sole();
    expect($settlement->journal_id)->toBeNull()
        ->and($adjustment->employee_id)->toBe($this->employee->id)
        ->and($adjustment->amount)->toBe('60.00')
        ->and(PayrollAdjustment::query()->payable()->whereKey($adjustment->id)->exists())->toBeTrue();

    $supplierDeal = commissionAgreement();
    creditedSale('1', '2026-10-06', $supplierDeal);
    expect(fn () => app(SettleCommission::class)->handle($supplierDeal, '2026-10-15', '2026-10-15', 'payroll', null, null, $this->w->owner))
        ->toThrow(ValidationException::class, 'Only an employee');
});

it('sends a settlement above the finance limit to a second person and posts it on approval', function () {
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['payment_second_approval_above_minor' => 5_000]); // Tk 50
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);

    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->financeA);

    expect($settlement->status)->toBe(CommissionSettlement::PENDING)
        ->and($settlement->journal_id)->toBeNull()
        ->and(DB::table('journals')->where('source_type', $settlement->getMorphClass())->count())->toBe(0);

    // While it waits, its share is taken: settling again finds nothing.
    expect(fn () => app(SettleCommission::class)->handle($deal, '2026-10-16', '2026-10-16', 'payable', null, null, $this->w->owner))
        ->toThrow(ValidationException::class);

    expect(app(SettleCommission::class)->approve($settlement->fresh(), $this->w->owner, 'Checked the orders'))->toBeTrue();
    expect($settlement->fresh()->status)->toBe(CommissionSettlement::POSTED)
        ->and($settlement->fresh()->journal_id)->not->toBeNull();
});

it('rejects a waiting settlement, posting nothing, and its share can be settled again', function () {
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['payment_second_approval_above_minor' => 5_000]);
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->financeA);

    app(SettleCommission::class)->reject($settlement->fresh(), $this->w->owner, 'Wrong agent');

    expect($settlement->fresh()->status)->toBe(CommissionSettlement::REJECTED)
        ->and(app(CommissionTracker::class)->progress($deal->fresh(['items', 'tiers']))['to_settle'])->toBe('100.00');
});

it('sends a target deal promising more than the limit to a second person before it counts', function () {
    $deal = commissionAgreement([
        'deal_type' => 'target', 'target_basis' => 'amount', 'valid_to' => '2026-10-31', 'rate' => null,
        'tiers' => [['threshold' => '100000', 'reward' => '25000']],
    ]);

    expect($deal->status)->toBe(CommissionAgreement::PENDING);
    expect(fn () => app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner))
        ->toThrow(ValidationException::class, 'Waiting for approval');

    expect(app(SaveCommissionAgreement::class)->approve($deal->fresh(), $this->w->owner, 'Agreed with Belal in person'))->toBeTrue()
        ->and($deal->fresh()->status)->toBe(CommissionAgreement::ACTIVE);
});

it('refuses a deal that makes no sense', function (array $overrides, string $message) {
    expect(fn () => commissionAgreement($overrides))->toThrow(ValidationException::class, $message);
})->with([
    'percent above 100' => [['deal_type' => 'percent', 'rate' => '150'], 'more than 100'],
    'no rate' => [['rate' => null], 'amount per unit'],
    'target without an end' => [['deal_type' => 'target', 'tiers' => [['threshold' => '5', 'reward' => '5']]], 'end date'],
    'nothing counts' => [['counts_credited_sales' => false], 'which sales count'],
    'own purchases for a supplier' => [['counts_own_purchases' => true], "Only a customer's agreement"],
    'ends before it starts' => [['valid_to' => '2026-09-01'], 'before the start date'],
]);

it('keeps the settled terms: after a settlement only the end date and the note change', function () {
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner);

    $input = [
        'agreed_on' => '2026-10-01', 'party_type' => 'supplier', 'party_id' => $this->agent->id,
        'valid_from' => '2026-10-01', 'valid_to' => '2026-10-20', 'deal_type' => 'per_unit', 'rate' => '99',
        'counts_credited_sales' => true, 'product_ids' => [$this->s->honey->id], 'note' => 'Ends with the season',
    ];
    app(SaveCommissionAgreement::class)->update($deal, $input, $this->w->owner);

    expect($deal->fresh()->rate)->toBe('20.0000')
        ->and($deal->fresh()->valid_to->toDateString())->toBe('2026-10-20');

    expect(fn () => app(SaveCommissionAgreement::class)->update($deal, ['valid_to' => '2026-10-10'] + $input, $this->w->owner))
        ->toThrow(ValidationException::class, 'already settled');
});

it('reverses a settlement through the corrections engine: its journal is mirrored, it is kept, and the next settlement takes the money again', function () {
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner);

    $result = $this->s->submit($this->s->input(CommissionSettlementActionPolicy::TYPE, $settlement, DocumentAction::Reverse), $this->w->financeA);

    expect($result->message)->toContain('reversed')
        ->and($settlement->fresh()->isReversed())->toBeTrue()
        ->and($this->w->balance(Account::bySlug('commission_payable')))->toBe(0)
        ->and($this->w->balance(Account::bySlug('sales_commission')))->toBe(0);

    $again = app(SettleCommission::class)->handle($deal, '2026-10-16', '2026-10-15', 'payable', null, null, $this->w->owner);
    expect($again->amount)->toBe('100.0000');
});

it('refuses to reverse a settlement while a payment of it stands, and reversing the payment makes it owed again', function () {
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->owner);
    $payment = app(PayCommission::class)->handle($settlement, '2026-10-16', '100', $this->w->cash, null, $this->w->owner);

    $availability = app(CommissionSettlementActionPolicy::class)->availability($settlement->fresh(), $this->w->owner, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->message)->toContain('reverse the payment first');

    $this->s->submit($this->s->input(CommissionPaymentActionPolicy::TYPE, $payment, DocumentAction::Reverse), $this->w->financeA);

    expect($settlement->fresh()->owed()->toTaka())->toBe('100.00')
        ->and(app(CommissionSettlementActionPolicy::class)->availability($settlement->fresh(), $this->w->owner, DocumentAction::Reverse)->allowed)->toBeTrue();
});

it('reverses a payroll settlement by cancelling its addition while the month takes inputs', function () {
    $deal = commissionAgreement(['party_type' => 'employee', 'party_id' => $this->employee->id]);
    creditedSale('3', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payroll', null, null, $this->w->owner);

    $this->s->submit($this->s->input(CommissionSettlementActionPolicy::TYPE, $settlement, DocumentAction::Reverse), $this->w->financeA);

    $adjustment = PayrollAdjustment::query()->whereKey($settlement->payroll_adjustment_id)->sole();
    expect($settlement->fresh()->isReversed())->toBeTrue()
        ->and($adjustment->cancelled_at)->not->toBeNull()
        ->and(PayrollAdjustment::query()->payable()->whereKey($adjustment->id)->exists())->toBeFalse();
});

it('sends a reversal above the limit to a second person', function () {
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['payment_second_approval_above_minor' => 5_000]);
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->financeA);
    app(SettleCommission::class)->approve($settlement->fresh(), $this->w->owner);

    $availability = app(CommissionSettlementActionPolicy::class)->availability($settlement->fresh(), $this->w->owner, DocumentAction::Reverse);

    expect($availability->approval)->toBe(ApprovalRoute::SecondPerson);
});

it('shows a waiting settlement and agreement in the approval inbox with the facts to decide on', function () {
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['payment_second_approval_above_minor' => 5_000]);
    $deal = commissionAgreement();
    creditedSale('5', '2026-10-05', $deal);
    $settlement = app(SettleCommission::class)->handle($deal, '2026-10-15', '2026-10-15', 'payable', null, null, $this->w->financeA);

    $card = app(ApprovalCards::class)->present($settlement->fresh()->approvalRequest);

    expect($card['type'])->toBe('Commission settlement')
        ->and($card['approve_url'])->toContain('/sales/commission-settlements/');
    expect(collect($card['details'])->pluck('label')->all())->toContain('Amount', 'Books');
});
