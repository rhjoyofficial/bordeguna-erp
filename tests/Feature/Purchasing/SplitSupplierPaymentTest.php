<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentMoneyLine;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): one supplier payment split across
 * several money accounts ("some from bank, some from bKash, the rest stays
 * due"). One journal, one credit per account, each account checked on its own,
 * the approval rules on the whole, Reverse and Correct over every line.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    // Tk 1,000 and Tk 400.
    $this->billA = $this->d->bill($this->d->order([[$this->d->salt, '50', '20.00']]), null, 'INV-A');
    $this->billB = $this->d->bill($this->d->order([[$this->d->sugar, '40', '10.00']]), null, 'INV-B');

    $this->bkash = MoneyAccount::create([
        'business_id' => $this->w->business->id, 'name' => 'bKash Merchant', 'type' => 'mfs', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->pay = fn (array $split, string $amount = '1000', ?MoneyAccount $first = null, $bill = null) => app(RecordSupplierPayment::class)->handle(
        supplier: $this->d->supplier,
        moneyAccount: $first ?? $this->w->bank,
        paidOn: '2026-10-16',
        allocations: [['bill' => ($bill ?? $this->billA)->fresh(), 'amount' => $amount]],
        createdBy: $this->w->owner,
        reference: 'SPLIT-1',
        splitLines: $split,
    );

    $this->creditOf = fn (SupplierPayment $payment, MoneyAccount $account) => (int) Journal::query()->find($this->m->journalOf($payment))
        ->lines()->where('money_account_id', $account->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('pays one bill from the bank and from cash: one journal, one credit per account, the books tie', function () {
    $payment = ($this->pay)([['money_account' => $this->w->cash, 'amount' => '300']]);

    $journals = Journal::query()->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->get();

    expect($journals)->toHaveCount(1)
        ->and($journals->first()->balance()->isZero())->toBeTrue()
        ->and(($this->creditOf)($payment, $this->w->bank))->toBe(-70000)
        ->and(($this->creditOf)($payment, $this->w->cash))->toBe(-30000)
        ->and($payment->moneyLines()->pluck('amount', 'money_account_id')->map(fn ($a) => (float) $a)->all())
        ->toBe([$this->w->bank->id => 700.0, $this->w->cash->id => 300.0])
        ->and($payment->paidFromLabel())->toBe('BRAC Bank, Cash')
        ->and($this->billA->fresh()->outstanding()->minor)->toBe(0)
        ->and($this->m->problems())->toBe([]);
});

it('a part payment from two accounts leaves the rest owed to the supplier', function () {
    $payment = ($this->pay)([['money_account' => $this->bkash, 'amount' => '200']], '600');

    expect((float) $payment->amount)->toBe(600.0)
        ->and(($this->creditOf)($payment, $this->w->bank))->toBe(-40000)
        ->and(($this->creditOf)($payment, $this->bkash))->toBe(-20000)
        ->and($this->billA->fresh()->outstanding()->minor)->toBe(40000)
        ->and($this->m->problems())->toBe([]);
});

it('one account is exactly the old payment: no split rows stored', function () {
    $payment = ($this->pay)([]);

    expect(SupplierPaymentMoneyLine::query()->count())->toBe(0)
        ->and(($this->creditOf)($payment, $this->w->bank))->toBe(-100000)
        ->and($payment->cashLines())->toHaveCount(1)
        ->and($this->m->problems())->toBe([]);
});

it('checks every account on its own: a cash box that holds too little refuses, nothing is written', function () {
    config(['accounting.money_availability' => true]);
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update(['refuse_negative_money' => true]);
    $payments = SupplierPayment::query()->count();
    $journals = $this->m->journalCount();

    expect(fn () => ($this->pay)([['money_account' => $this->w->cash, 'amount' => '300']]))
        ->toThrow(ValidationException::class);

    expect(SupplierPayment::query()->count())->toBe($payments)
        ->and($this->m->journalCount())->toBe($journals)
        ->and(SupplierPaymentMoneyLine::query()->count())->toBe(0);
});

it('refuses the same account twice, an empty line, and other lines that leave the first account nothing', function () {
    $errorsOf = function (array $split, string $amount = '1000') {
        try {
            ($this->pay)($split, $amount);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errorsOf([['money_account' => $this->w->bank, 'amount' => '100']]))->toBe(['split_lines.0.money_account_id'])
        ->and($errorsOf([['money_account' => $this->w->cash, 'amount' => '0']]))->toBe(['split_lines.0.amount'])
        ->and($errorsOf([['money_account' => $this->w->cash, 'amount' => '600'], ['money_account' => $this->bkash, 'amount' => '400']]))->toBe(['split_lines'])
        ->and(SupplierPayment::query()->count())->toBe(0);
});

it('refuses an inactive account on a split line', function () {
    $this->bkash->update(['is_active' => false]);

    expect(fn () => ($this->pay)([['money_account' => $this->bkash->fresh(), 'amount' => '100']]))
        ->toThrow(ValidationException::class, 'no longer active');
});

it('the approval limit applies to the whole split payment, and approving it replays the split', function () {
    config(['accounting.purchasing_approvals' => true]);
    FinancePolicy::query()->where('business_id', $this->w->business->id)->update([
        'purchasing_approval_at_or_above_minor' => 100_000, // Tk 1,000
        'purchasing_approve_new_supplier' => false,
    ]);

    // Tk 700 + Tk 300: each line is under the limit, the payment is not.
    try {
        ($this->pay)([['money_account' => $this->w->cash, 'amount' => '300']]);
        $this->fail('The split payment should wait for approval.');
    } catch (PurchaseApprovalRequired $e) {
        $approval = app(PurchasingApprovalGate::class)->hold($e, $this->w->financeA);
    }

    expect(SupplierPayment::query()->count())->toBe(0)
        ->and($approval->payload['split_lines'])->toBe([['money_account_id' => $this->w->cash->id, 'amount' => '300.00']]);

    $payment = app(PurchasingApprovalGate::class)->approve(PurchaseApproval::query()->find($approval->id), $this->w->owner);

    expect(($this->creditOf)($payment, $this->w->bank))->toBe(-70000)
        ->and(($this->creditOf)($payment, $this->w->cash))->toBe(-30000)
        ->and($this->m->problems())->toBe([]);
});

it('Reverse gives every account its money back together', function () {
    $payment = ($this->pay)([['money_account' => $this->w->cash, 'amount' => '250'], ['money_account' => $this->bkash, 'amount' => '150']]);
    $cash = $this->w->balance('11101');

    $result = $this->m->reverse($payment, $this->w->financeB);
    $reversal = $result->reversalJournal;

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and((int) $reversal->lines()->where('money_account_id', $this->w->bank->id)->sum('amount_minor'))->toBe(60000)
        ->and((int) $reversal->lines()->where('money_account_id', $this->w->cash->id)->sum('amount_minor'))->toBe(25000)
        ->and((int) $reversal->lines()->where('money_account_id', $this->bkash->id)->sum('amount_minor'))->toBe(15000)
        ->and($this->w->balance('11101') - $cash)->toBe(25000)
        ->and($this->billA->fresh()->outstanding()->minor)->toBe(100000)
        ->and($this->m->problems())->toBe([]);
});

it('Correct carries the split: a split payment corrected to another split, the books tie', function () {
    $payment = ($this->pay)([['money_account' => $this->w->cash, 'amount' => '300']]);

    $result = $this->m->correct($payment, $this->w->financeB, $this->m->paymentForm($payment, [
        'split_lines' => [['money_account_id' => $this->bkash->id, 'amount' => '500']],
    ]));
    $successor = $result->successor;

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($payment->fresh()->isReversed())->toBeTrue()
        ->and(($this->creditOf)($successor, $this->w->bank))->toBe(-50000)
        ->and(($this->creditOf)($successor, $this->bkash))->toBe(-50000)
        ->and(($this->creditOf)($successor, $this->w->cash))->toBe(0)
        ->and($this->m->problems())->toBe([]);
});

it('the payment screen takes split lines, and a double submit is caught by the duplicate warning', function () {
    $form = [
        'supplier_id' => $this->d->supplier->id, 'money_account_id' => $this->w->bank->id, 'paid_on' => '2026-10-16',
        'allocations' => [['supplier_bill_id' => $this->billB->id, 'amount' => '400']],
        'split_lines' => [['money_account_id' => $this->bkash->id, 'amount' => '100']],
    ];

    $this->actingAs($this->w->owner)->post('/purchasing/payments', $form)->assertSessionHasNoErrors()->assertRedirect();

    $payment = SupplierPayment::query()->sole();

    expect(($this->creditOf)($payment, $this->w->bank))->toBe(-30000)
        ->and(($this->creditOf)($payment, $this->bkash))->toBe(-10000);

    // The same payment again: "Possible duplicate", nothing written.
    $this->actingAs($this->w->owner)->post('/purchasing/payments', $form)->assertSessionHasErrors('_duplicate');

    expect(SupplierPayment::query()->count())->toBe(1);

    $this->actingAs($this->w->owner)->get("/purchasing/payments/{$payment->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('cashLines.1.account', 'bKash Merchant')->where('cashLines.1.amount', '100.00'));
});

it('a split line of another business is refused on the screen', function () {
    $this->w->enter($this->w->other);
    $foreign = MoneyAccount::create([
        'business_id' => $this->w->other->id, 'name' => 'Other bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $this->w->enter($this->w->business);

    $this->actingAs($this->w->owner)->post('/purchasing/payments', [
        'supplier_id' => $this->d->supplier->id, 'money_account_id' => $this->w->bank->id, 'paid_on' => '2026-10-16',
        'allocations' => [['supplier_bill_id' => $this->billB->id, 'amount' => '400']],
        'split_lines' => [['money_account_id' => $foreign->id, 'amount' => '100']],
    ])->assertSessionHasErrors('split_lines.0.money_account_id');

    expect(SupplierPayment::query()->count())->toBe(0);
});
