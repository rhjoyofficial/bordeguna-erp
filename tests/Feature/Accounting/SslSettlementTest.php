<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Corrections\SslSettlementActionPolicy;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SslClearing;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AccountsWorld;

/**
 * Joy's answer 9 (25 Oct 2026): "Paid online (SSL)" money waits in SSL Commerz
 * Clearing; an SSL settlement moves the gross out of it, SSL's charge to 6475
 * and the net into the bank, in one balanced journal; Reverse through the
 * corrections engine; a report of what SSL took and kept.
 */
beforeEach(function () {
    $this->a = AccountsWorld::make('2026-10-25 10:00:00');
    $this->w = $this->a->w;
    (new SalesAccountMappingSeeder)->run();

    $this->clearing = SslClearing::for($this->w->business->id);
    $this->customer = Customer::query()->create(['business_id' => $this->w->business->id, 'code' => 'ONLINE', 'name' => 'Online buyer']);

    // An invoice of 3,400 for a shop order the customer paid through SSL.
    $this->invoice = SalesInvoice::create([
        'business_id' => $this->w->business->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'INV-SSL-1',
        'invoice_date' => '2026-10-10', 'subtotal' => '3400', 'total' => '3400', 'created_by' => $this->w->owner->id,
    ]);
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-10-10'), [
        JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka('3400'), null, $this->customer),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('3400')),
    ], source: $this->invoice), $this->w->owner);

    $this->paidOnline = fn () => app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $this->clearing,
        receivedOn: '2026-10-10',
        allocations: [['invoice' => $this->invoice, 'amount' => '3400']],
        createdBy: $this->w->owner,
        reference: 'SSL-TXN-1',
    );

    $this->as = fn () => $this->actingAs($this->w->owner)->withSession(['current_business_id' => $this->w->business->id]);
    $this->settle = fn (array $extra = []) => ($this->as)()->post(route('accounting.ssl-settlements.store'), $extra + [
        'settled_on' => '2026-10-20', 'bank_money_account_id' => $this->w->bank->id, 'gross' => '3400', 'fee' => '85', 'reference' => 'SSL-PO-77',
    ]);
    $this->onAccount = fn (MoneyAccount $account) => (int) DB::table('journal_lines')->where('money_account_id', $account->id)->sum('amount_minor');
    $this->onCode = fn (string $code) => (int) JournalLine::where('account_id', Account::where('code', $code)->sole()->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('keeps one SSL Commerz Clearing account per business, a gateway account under 1146', function () {
    expect($this->clearing->name)->toBe('SSL Commerz Clearing')
        ->and($this->clearing->isGateway())->toBeTrue()
        ->and($this->clearing->isSslClearing())->toBeTrue()
        ->and((int) $this->clearing->account_id)->toBe((int) Account::where('code', '1146')->sole()->id)
        ->and(SslClearing::for($this->w->business->id)->id)->toBe($this->clearing->id)
        ->and(MoneyAccount::query()->where('system_key', 'ssl_clearing')->count())->toBe(1);
});

it('takes a paid-online receipt into the clearing account, but still refuses any other gateway account by hand', function () {
    ($this->paidOnline)();

    expect(($this->onAccount)($this->clearing))->toBe(340000)
        ->and($this->invoice->fresh()->outstanding()->minor)->toBe(0);

    $other = MoneyAccount::create(['business_id' => $this->w->business->id, 'name' => 'bKash gateway', 'type' => 'gateway', 'account_id' => $this->clearing->account_id]);

    expect(fn () => app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer, moneyAccount: $other, receivedOn: '2026-10-10',
        allocations: [['invoice' => $this->invoice, 'amount' => '1']], createdBy: $this->w->owner,
    ))->toThrow(ValidationException::class, 'payment gateway');
});

it('settles gross out of clearing, the SSL charge to 6475 and the net into the bank, in one balanced journal', function () {
    ($this->paidOnline)();

    ($this->settle)()->assertSessionHasNoErrors();

    $settlement = SslSettlement::query()->sole();
    $lines = JournalLine::query()->where('journal_id', $settlement->journal_id)->get();

    expect($settlement->number)->toStartWith('SSL-')
        ->and((string) $settlement->net)->toBe('3315.00')
        ->and($lines)->toHaveCount(3)
        ->and((int) $lines->sum('amount_minor'))->toBe(0)
        ->and(($this->onAccount)($this->w->bank))->toBe(331500)
        ->and(($this->onCode)('6475'))->toBe(8500)
        // Nothing left waiting for SSL.
        ->and(($this->onAccount)($this->clearing))->toBe(0)
        ->and($this->a->ledgerBalances())->toBeTrue();

    ($this->as)()->get(route('accounting.ssl-settlements.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settlements.data.0.number', $settlement->number)
            ->where('clearing.balance', '0.00')
            ->where('settlements.data.0.corrections.actions.0.action', 'reverse')
            ->etc());
});

it('refuses a charge as large as the gross, a future date and the clearing account as the bank', function () {
    ($this->settle)(['fee' => '3400'])->assertSessionHasErrors('fee');
    ($this->settle)(['settled_on' => '2026-10-26'])->assertSessionHasErrors('settled_on');
    ($this->settle)(['bank_money_account_id' => $this->clearing->id])->assertSessionHasErrors('bank_money_account_id');

    expect(SslSettlement::count())->toBe(0);
});

it('warns about a second payout of the same gross into the same bank, and saves it on Save anyway', function () {
    ($this->settle)()->assertSessionHasNoErrors();
    ($this->settle)(['reference' => null])->assertSessionHasErrors('_duplicate');

    expect(SslSettlement::count())->toBe(1);

    $ack = session('duplicateWarning')['ack_token'];
    ($this->settle)(['reference' => null, 'duplicate_ack' => $ack])->assertSessionHasNoErrors();

    expect(SslSettlement::count())->toBe(2);
});

it('reverses through the corrections engine: bank, charge and clearing all move back', function () {
    ($this->paidOnline)();
    ($this->settle)()->assertSessionHasNoErrors();
    $settlement = SslSettlement::query()->sole();

    $result = $this->a->submit(SslSettlementActionPolicy::TYPE, $settlement, DocumentAction::Reverse, $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($settlement->fresh()->isReversed())->toBeTrue()
        ->and(($this->onAccount)($this->w->bank))->toBe(0)
        ->and(($this->onCode)('6475'))->toBe(0)
        ->and(($this->onAccount)($this->clearing))->toBe(340000)
        ->and($this->a->ledgerBalances())->toBeTrue();

    // Finance (no accounting.journal.post) sees no button.
    expect(app(SslSettlementActionPolicy::class)->actionsFor($settlement->fresh(), $this->w->financeB)->actions)->toBe([]);
});

it('reports what SSL took for us, what it kept, what reached the bank and what is still in clearing, and exports it', function () {
    ($this->paidOnline)();
    ($this->settle)(['gross' => '3000', 'fee' => '75'])->assertSessionHasNoErrors();

    ($this->as)()->get(route('accounting.reports.ssl-commerz', ['from' => '2026-10-01', 'to' => '2026-10-25']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.opening', 0)
            ->where('report.received', 340000)
            ->where('report.settled_gross', 300000)
            ->where('report.fee', 7500)
            ->where('report.net', 292500)
            ->where('report.closing', 40000)
            ->has('report.settlements', 1)
            ->etc());

    $response = ($this->as)()->get(route('accounting.reports.ssl-commerz.export', ['from' => '2026-10-01', 'to' => '2026-10-25']));
    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
});
