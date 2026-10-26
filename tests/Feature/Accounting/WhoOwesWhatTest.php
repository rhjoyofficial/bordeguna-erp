<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyBalances;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PartyWorld;

/**
 * ⭐ Who owes what (Joy, 26 Oct 2026: "from two place i needs to see how much
 * ayesha ows ... for all i needs in one place"). Every party on one page; a
 * firm that is both is ONE line with the same net as its card.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->p = PartyWorld::make();
    $this->w = $this->p->w;
    $this->w->record('100000', '2026-10-01');

    $this->otherCustomer = Customer::create(['business_id' => $this->w->business->id, 'code' => 'KARIM', 'name' => 'Karim Store', 'type' => 'retail', 'phone' => '01722000000']);
    $this->otherSupplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'JONEY', 'name' => 'Joney Printing', 'phone' => '01733000000']);
});

afterEach(fn () => Carbon::setTestNow());

it('puts the Ayesha case on one line with the right net, next to everyone else', function () {
    // The firm that is both: invoice 39,931 + our unused advance 17,400 = 57,331 in our favour.
    $this->p->invoice('39931', '2026-10-02');
    app(RecordSupplierAdvance::class)->pay(supplier: $this->p->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-03', amount: '17400', createdBy: $this->w->financeA);
    // A plain customer who owes us, a plain supplier we owe.
    $this->p->invoice('1450', '2026-10-04', $this->otherCustomer);
    $this->p->bill('5000', '2026-10-05', $this->otherSupplier);

    $rows = PartyBalances::rows($this->w->owner)->keyBy('name');

    expect($rows)->toHaveCount(3)
        ->and($rows['Rahman Traders'])->kind->toBe('both')
        ->they_owe->toBe(3_993_100)->our_advance->toBe(1_740_000)->we_owe->toBe(0)->net->toBe(5_733_100)
        ->and($rows['Karim Store'])->kind->toBe('customer')->net->toBe(145_000)
        ->and($rows['Joney Printing'])->kind->toBe('supplier')->we_owe->toBe(500_000)->net->toBe(-500_000);

    expect(PartyBalances::totals(PartyBalances::rows($this->w->owner)))
        ->toBe(['they_owe' => 5_878_100, 'we_owe' => 500_000, 'net' => 5_378_100, 'owe_us_count' => 2, 'we_owe_count' => 1]);

    $this->actingAs($this->w->financeA)->get(route('accounting.who-owes-what'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/who-owes-what/index')
            ->has('rows', 3)
            ->where('rows.0.name', 'Rahman Traders')
            ->where('rows.0.kind', 'both')
            ->where('rows.0.net', '57,331.00')
            ->where('rows.0.net_side', 'us')
            ->where('rows.0.they_owe', '39,931.00')
            ->where('rows.0.our_advance', '17,400.00')
            ->where('rows.2.name', 'Joney Printing')
            ->where('rows.2.net_side', 'them')
            ->where('totals.they_owe', '58,781.00')
            ->where('totals.we_owe', '5,000.00'));
});

it('filters to those who owe us, those we owe, a kind, or a name', function () {
    $this->p->invoice('1450', '2026-10-04', $this->otherCustomer);
    $this->p->bill('5000', '2026-10-05', $this->otherSupplier);

    $as = fn (array $q) => $this->actingAs($this->w->financeA)->get(route('accounting.who-owes-what', $q));

    $as(['side' => 'owe_us'])->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'Karim Store'));
    $as(['side' => 'we_owe'])->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'Joney Printing'));
    $as(['kind' => 'supplier'])->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.kind', 'supplier'));
    $as(['q' => '0172'])->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'Karim Store'));
});

it('shows what a courier still holds for us', function () {
    $courier = MoneyAccount::create([
        'business_id' => $this->w->business->id, 'name' => 'CarryBee - Bionic', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-10-11'), [
        JournalLineDraft::debit(Account::bySlug('courier_receivable')->id, Money::ofTaka('1450'), moneyAccount: $courier),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('1450')),
    ]), $this->w->owner);

    $row = PartyBalances::rows($this->w->owner)->firstWhere('kind', 'courier');

    expect($row)->name->toBe('CarryBee - Bionic')->they_owe->toBe(145_000)->net->toBe(145_000);
});

it('leaves out everyone whose books are square', function () {
    expect(PartyBalances::rows($this->w->owner))->toBeEmpty();
});

it('downloads the same list as a spreadsheet', function () {
    $this->p->bill('5000', '2026-10-05', $this->otherSupplier);

    $csv = $this->actingAs($this->w->financeA)->get(route('accounting.who-owes-what.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('"Joney Printing",Supplier,01733000000,0.00,0.00,5000.00,0.00,5000.00,"We owe them"');
});

it('puts the both-sides net in the headline of the customer page', function () {
    $this->p->invoice('39931', '2026-10-02');
    app(RecordSupplierAdvance::class)->pay(supplier: $this->p->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-03', amount: '17400', createdBy: $this->w->financeA);

    $this->actingAs($this->w->financeA)->get(route('sales.customers.show', $this->p->customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('party.position.net', '57,331.00')->where('balance.amount', '39,931.00'));
});
