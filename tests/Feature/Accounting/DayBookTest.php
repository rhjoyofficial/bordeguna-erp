<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Day Book (accountant's ask, 19 Sep 2026): every journal of every module
 * in a window, in posting order, read straight from the ledger.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Book Co', 'slug' => 'book-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $this->reader = User::factory()->create(['name' => 'Reader One']);
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->other = User::factory()->create(['name' => 'Other Poster']);
    $this->other->businesses()->attach($this->business->id);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->cash = moneyHead('cash_in_hand');
    $this->capital = Account::bySlug('owners_capital');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'is_active' => true,
        'account_id' => moneyHead('cash_at_bank')->id,
    ]);

    $this->journal = fn (string $date, string $taka, ?User $by = null, JournalType $type = JournalType::Manual, string $memo = 'Capital') => app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse($date),
            lines: [
                JournalLineDraft::debit($this->cash->id, Money::ofTaka($taka)),
                JournalLineDraft::credit($this->capital->id, Money::ofTaka($taka)),
            ],
            memo: $memo,
            type: $type,
        ),
        $by ?? $this->reader,
    );

    $this->income = fn (string $date, string $taka) => app(RecordIncomeVoucher::class)->handle(
        moneyAccount: $this->bank,
        receivedOn: $date,
        lines: [['account' => Account::where('code', '4910')->sole(), 'amount' => $taka, 'description' => 'Empty drums sold']],
        createdBy: $this->reader,
        receivedFrom: 'Scrap dealer',
    );
});

afterEach(fn () => Carbon::setTestNow());

it('opens on today and shows only what was posted today', function () {
    ($this->journal)('2026-09-18', '100', memo: 'Yesterday');
    ($this->journal)('2026-09-19', '250', memo: 'Today');

    $this->actingAs($this->reader)->get('/accounting/day-book')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/day-book/index')
            ->where('from', '2026-09-19')
            ->where('to', '2026-09-19')
            ->where('count', 1)
            ->where('journals.data.0.memo', 'Today')
            ->where('total', '250.00'));
});

it('lists journals from other modules beside manual ones, each under its own kind', function () {
    ($this->journal)('2026-09-19', '100');
    ($this->income)('2026-09-19', '1500');
    ($this->journal)('2026-09-19', '40', type: JournalType::OpeningBalance, memo: 'Opening');

    $this->actingAs($this->reader)->get('/accounting/day-book')
        ->assertInertia(fn (Assert $page) => $page
            ->where('count', 3)
            ->where('journals.data.0.kind', 'Journal voucher')
            ->where('journals.data.1.kind', 'Income voucher')
            // The source document's own number travels with the row.
            ->where('journals.data.1.document_number', 'INC-2026-27-0001')
            ->where('journals.data.2.kind', 'Opening balance')
            ->where('kinds', ['Income voucher', 'Journal voucher', 'Opening balance'])
            ->where('total', '1,640.00'));
});

it('orders by date and then by posting sequence, not by when the date was typed', function () {
    $late = ($this->journal)('2026-09-19', '10', memo: 'Posted first, dated later');
    $early = ($this->journal)('2026-09-17', '20', memo: 'Posted second, dated earlier');
    $same = ($this->journal)('2026-09-19', '30', memo: 'Same day, posted last');

    $this->actingAs($this->reader)->get('/accounting/day-book?from=2026-09-17&to=2026-09-19')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journals.data.0.id', $early->id)
            ->where('journals.data.1.id', $late->id)
            ->where('journals.data.2.id', $same->id));
});

it('shows each line on its own side with the account it hit', function () {
    ($this->journal)('2026-09-19', '500');

    $this->actingAs($this->reader)->get('/accounting/day-book')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journals.data.0.lines.0.code', '11101')
            ->where('journals.data.0.lines.0.debit', '500.00')
            ->where('journals.data.0.lines.0.credit', '')
            ->where('journals.data.0.lines.1.code', '3100')
            ->where('journals.data.0.lines.1.debit', '')
            ->where('journals.data.0.lines.1.credit', '500.00'));
});

it('filters by voucher kind and by who posted', function () {
    ($this->journal)('2026-09-19', '100');
    ($this->journal)('2026-09-19', '70', by: $this->other);
    ($this->income)('2026-09-19', '1500');

    $this->actingAs($this->reader)->get('/accounting/day-book?kind='.urlencode('Income voucher'))
        ->assertInertia(fn (Assert $page) => $page->where('count', 1)->where('total', '1,500.00'));

    $this->actingAs($this->reader)->get('/accounting/day-book?kind='.urlencode('Journal voucher'))
        ->assertInertia(fn (Assert $page) => $page->where('count', 2)->where('total', '170.00'));

    $this->actingAs($this->reader)->get("/accounting/day-book?user={$this->other->id}")
        ->assertInertia(fn (Assert $page) => $page->where('count', 1)->where('total', '70.00'));

    // ⚠ A kind nobody has posted matches NOTHING, never everything.
    $this->actingAs($this->reader)->get('/accounting/day-book?kind=Nonsense')
        ->assertInertia(fn (Assert $page) => $page->where('count', 0));
});

it('never shows another business its journals', function () {
    ($this->journal)('2026-09-19', '100');

    $elsewhere = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($elsewhere->id);
    moneyHead('cash_in_hand');
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-09-19'),
        lines: [
            JournalLineDraft::debit($this->cash->id, Money::ofTaka('999')),
            JournalLineDraft::credit($this->capital->id, Money::ofTaka('999')),
        ],
        memo: 'Not yours',
    ), $this->other);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->reader)->get('/accounting/day-book')
        ->assertInertia(fn (Assert $page) => $page->where('count', 1)->where('total', '100.00'));
});

it('exports the window line by line', function () {
    ($this->journal)('2026-09-19', '500');
    ($this->journal)('2026-09-10', '60', memo: 'Outside the window');

    $csv = $this->actingAs($this->reader)->get('/accounting/day-book/export?from=2026-09-19&to=2026-09-19')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Journal voucher')
        ->and($csv)->toContain('1110')
        ->and($csv)->toContain('500.00')
        ->and($csv)->not->toContain('Outside the window');
});

it('is closed to someone who may not read the books', function () {
    $this->actingAs($this->outsider)->get('/accounting/day-book')->assertForbidden();
    $this->actingAs($this->outsider)->get('/accounting/day-book/export')->assertForbidden();
});

it('refuses a window that ends before it starts', function () {
    $this->actingAs($this->reader)->get('/accounting/day-book?from=2026-09-19&to=2026-09-01')
        ->assertSessionHasErrors('to');
});
