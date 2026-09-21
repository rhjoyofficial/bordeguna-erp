<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The voucher screens and who may do what on them. The engine's own rules are
 * in VoucherEngineTest; this file is the HTTP surface.
 *
 *   accounting.view              read lists, vouchers, prints
 *   accounting.voucher.prepare   draft, edit a draft, delete a draft, duplicate, attach
 *   accounting.voucher.post      post, reverse
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-19 10:00:00');

    $this->business = Business::create(['name' => 'Voucher Co', 'slug' => 'voucher-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    foreach (['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $make = function (string $roleName, array $permissions) {
        $role = Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['name' => $roleName]);
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = $make('Owner', ['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post']);
    $this->finance = $make('Finance', ['accounting.view', 'accounting.voucher.prepare']);
    $this->reader = $make('Reader', ['accounting.view']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->rent = Account::where('code', '6051')->sole();

    $this->payload = fn (array $overrides = []) => $overrides + [
        'voucher_date' => '2026-09-19',
        'money_account_id' => $this->bank->id,
        'counterparty' => 'Adabor landlord',
        'narration' => 'Being rent for September',
        'instrument_type' => 'cheque',
        'instrument_number' => 'CHQ-001122',
        'lines' => [['account_id' => $this->rent->id, 'amount' => '25000', 'narration' => 'September rent']],
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('shows the form every head of the chart, greys the ones a voucher may not use with where they are posted, and marks the party-enabled ones', function () {
    AccountMapping::assign('sales.accounts_receivable', Account::where('code', '1140')->sole(), Carbon::parse('2000-01-01'));
    AccountMapping::assign('payroll.earning.default', Account::where('code', '6131')->sole(), Carbon::parse('2000-01-01'));

    $this->actingAs($this->finance)->get('/accounting/vouchers/payment/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/vouchers/form')
            ->where('type.value', 'payment')
            ->where('type.fixed_side', 'debit')
            ->where('moneyAccounts.0.label', 'BRAC Bank')
            ->where('moneyAccounts.0.hint', null)
            ->where('accounts', function ($accounts) {
                $accounts = collect($accounts)->keyBy('label');
                $row = fn (string $label) => $accounts->get($label) ?? throw new RuntimeException("{$label} is missing from the picker");

                // ⭐ Nothing in the chart is missing (Joy, 21 Sep 2026).
                expect($accounts)->toHaveCount(Account::count());

                expect($row('6051 - Office Rent'))->toMatchArray(['usable' => true, 'heading' => false, 'hint' => null, 'party' => null])
                    ->and($row('1160 - Advances to Suppliers'))->toMatchArray(['usable' => true, 'party' => 'supplier'])
                    ->and($row('2150 - Advances from Customers'))->toMatchArray(['usable' => true, 'party' => 'customer'])
                    // cash and bank heads move through the money account field, and say so
                    ->and($row('1120 - Cash at Bank'))->toMatchArray(['usable' => false, 'hint' => 'Cash or bank: choose it in Paid from above.'])
                    ->and($row('1140 - Accounts Receivable'))->toMatchArray(['usable' => false, 'hint' => 'Posted by sales invoices. Record a customer\'s payment on the invoice.'])
                    ->and($row('6131 - Salaries and Wages'))->toMatchArray(['usable' => false, 'hint' => 'Posted by Payroll: payroll runs, loans and advances.'])
                    ->and($row('3900 - Opening Balance Equity'))->toMatchArray(['usable' => false, 'hint' => 'Used only by opening balances.'])
                    ->and($row('6050 - Office and Administration'))->toMatchArray(['usable' => false, 'heading' => true]);

                return true;
            }));
});

it('greys what a voucher refuses, and still refuses it when a form sends it anyway', function () {
    AccountMapping::assign('sales.accounts_receivable', Account::where('code', '1140')->sole(), Carbon::parse('2000-01-01'));

    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)([
        'lines' => [['account_id' => Account::where('code', '1140')->sole()->id, 'amount' => '500']],
    ]))->assertSessionHasErrors('lines.0.account_id');

    expect(Voucher::count())->toBe(0);
});

it('lists every Bank & cash account, and says why one cannot be chosen', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Petty Cash', 'type' => 'cash', 'is_active' => true, 'account_id' => null]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Old Cash Box', 'type' => 'cash', 'is_active' => false, 'account_id' => Account::bySlug('cash_in_hand')->id]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Pathao', 'type' => 'courier', 'is_active' => true, 'account_id' => Account::bySlug('courier_receivable')->id]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => 'gateway', 'is_active' => true, 'account_id' => Account::bySlug('gateway_receivable')->id]);

    $hints = fn (string $type) => collect($this->actingAs($this->owner)->get("/accounting/vouchers/{$type}/create")
        ->assertOk()->original->getData()['page']['props']['moneyAccounts'])->pluck('hint', 'label');

    $payment = $hints('payment');

    // Usable first, so the one to pick is at the top.
    expect($payment->keys()->first())->toBe('BRAC Bank')
        ->and($payment->all())->toBe([
            'BRAC Bank' => null,
            'Old Cash Box' => 'Switched off in Bank & cash.',
            'Pathao' => 'Courier money: settled from Sales > Courier payouts.',
            'Petty Cash' => 'Set "Reports under" for it in Bank & cash first.',
            'SSLCommerz' => 'Online-sale money: moved to the bank by a Contra voucher.',
        ]);

    // A contra may move money OUT of a gateway, so there it is usable.
    expect($hints('contra')->get('SSLCommerz'))->toBeNull();
});

it('refuses an unknown voucher type', function () {
    $this->actingAs($this->owner)->get('/accounting/vouchers/bogus')->assertNotFound();
    $this->actingAs($this->owner)->get('/accounting/vouchers/bogus/create')->assertNotFound();
});

it('saves a draft for someone who may only prepare, and posts nothing', function () {
    $this->actingAs($this->finance)->post('/accounting/vouchers/payment', ($this->payload)())
        ->assertRedirect();

    $voucher = Voucher::sole();

    expect($voucher->status)->toBe(VoucherStatus::Draft)
        ->and($voucher->created_by)->toBe($this->finance->id)
        ->and($voucher->instrument_number)->toBe('CHQ-001122')
        ->and(Journal::count())->toBe(0);
});

it('never posts on "save and post" for someone who may not post', function () {
    $this->actingAs($this->finance)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]))
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Saved as a draft'));

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Draft)->and(Journal::count())->toBe(0);
});

it('saves and posts in one press for someone who may post', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]))
        ->assertRedirect();

    $voucher = Voucher::sole();

    expect($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->voucher_number)->toBe('PV-2026-27-0001')
        ->and($voucher->posted_by)->toBe($this->owner->id);
});

it('keeps the draft when posting is refused, so nothing typed is lost', function () {
    // A future date passes drafting and fails posting.
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true, 'voucher_date' => '2026-09-30']))
        ->assertSessionHasErrors('voucher_date')
        // ⚠ Onto the SAVED draft, not back to the blank form: a second press
        // there would have made a second draft.
        ->assertRedirect('/accounting/vouchers/doc/'.Voucher::sole()->id.'/edit');

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Draft);
});

it('lets a draft be edited and deleted, and a posted voucher be neither', function () {
    $this->actingAs($this->finance)->post('/accounting/vouchers/payment', ($this->payload)());
    $voucher = Voucher::sole();

    $this->actingAs($this->finance)->put("/accounting/vouchers/doc/{$voucher->id}", ($this->payload)(['counterparty' => 'New landlord']))
        ->assertRedirect();
    expect($voucher->fresh()->counterparty)->toBe('New landlord');

    $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/post")->assertRedirect();

    $this->actingAs($this->finance)->get("/accounting/vouchers/doc/{$voucher->id}/edit")
        ->assertRedirect("/accounting/vouchers/doc/{$voucher->id}");
    $this->actingAs($this->finance)->put("/accounting/vouchers/doc/{$voucher->id}", ($this->payload)(['counterparty' => 'Changed after posting']))
        ->assertSessionHasErrors('voucher');
    $this->actingAs($this->finance)->delete("/accounting/vouchers/doc/{$voucher->id}")
        ->assertSessionHas('error');

    expect($voucher->fresh()->counterparty)->toBe('New landlord')->and(Voucher::count())->toBe(1);
});

it('lets only a poster post and reverse', function () {
    $this->actingAs($this->finance)->post('/accounting/vouchers/payment', ($this->payload)());
    $voucher = Voucher::sole();

    $this->actingAs($this->finance)->post("/accounting/vouchers/doc/{$voucher->id}/post")->assertForbidden();
    $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/post")->assertRedirect();

    $this->actingAs($this->finance)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", [])->assertSessionHasErrors('reason');

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Posted);

    $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", ['reason' => 'Wrong bank'])->assertRedirect();

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Reversed);
});

it('reverse and copy lands on the new draft\'s edit form', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]));
    $voucher = Voucher::sole();

    $response = $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", ['reason' => 'Amount was 26,000', 'copy' => true]);

    $copy = Voucher::where('copied_from_id', $voucher->id)->sole();
    $response->assertRedirect("/accounting/vouchers/doc/{$copy->id}/edit");

    expect($copy->status)->toBe(VoucherStatus::Draft)->and($voucher->fresh()->status)->toBe(VoucherStatus::Reversed);
});

it('duplicates a posted voucher into a draft without touching the original', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]));
    $voucher = Voucher::sole();

    $this->actingAs($this->finance)->post("/accounting/vouchers/doc/{$voucher->id}/duplicate")->assertRedirect();

    expect($voucher->fresh()->status)->toBe(VoucherStatus::Posted)
        ->and(Voucher::where('copied_from_id', $voucher->id)->sole()->status)->toBe(VoucherStatus::Draft);
});

it('shows and prints a posted voucher with its amount in words; a draft has no print', function () {
    $this->actingAs($this->finance)->post('/accounting/vouchers/payment', ($this->payload)());
    $voucher = Voucher::sole();

    $this->actingAs($this->reader)->get("/accounting/vouchers/doc/{$voucher->id}/print")->assertNotFound();

    $this->actingAs($this->owner)->post("/accounting/vouchers/doc/{$voucher->id}/post");

    $this->actingAs($this->reader)->get("/accounting/vouchers/doc/{$voucher->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/vouchers/show')
            ->where('voucher.number', 'PV-2026-27-0001')
            ->where('voucher.status', 'posted')
            ->where('voucher.total', '25,000.00')
            ->where('voucher.total_in_words', 'Taka Twenty Five Thousand Only')
            ->where('voucher.lines.0.debit', '25,000.00')
            ->where('voucher.instrument.number', 'CHQ-001122'));

    $this->actingAs($this->reader)->get("/accounting/vouchers/doc/{$voucher->id}/print")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/vouchers/print')->has('issuer'));
});

it('lists by type, puts drafts first, and searches number, name, narration and cheque', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]));
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['counterparty' => 'Khulna Honey House', 'instrument_number' => 'CHQ-999']));
    $this->actingAs($this->owner)->post('/accounting/vouchers/journal', [
        'voucher_date' => '2026-09-19',
        'lines' => [
            ['account_id' => Account::where('code', '6052')->sole()->id, 'side' => 'debit', 'amount' => '10'],
            ['account_id' => Account::where('code', '2160')->sole()->id, 'side' => 'credit', 'amount' => '10'],
        ],
    ]);

    $this->actingAs($this->reader)->get('/accounting/vouchers/payment')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/vouchers/index')
            ->has('vouchers.data', 2)
            ->where('vouchers.data.0.status', 'draft')
            ->where('vouchers.data.1.number', 'PV-2026-27-0001'));

    $this->actingAs($this->reader)->get('/accounting/vouchers/payment?search=CHQ-999')
        ->assertInertia(fn (Assert $page) => $page->has('vouchers.data', 1)->where('vouchers.data.0.counterparty', 'Khulna Honey House'));

    $this->actingAs($this->reader)->get('/accounting/vouchers/payment?status=posted')
        ->assertInertia(fn (Assert $page) => $page->has('vouchers.data', 1));

    $this->actingAs($this->reader)->get('/accounting/vouchers/journal')
        ->assertInertia(fn (Assert $page) => $page->has('vouchers.data', 1));
});

it('never opens another business\'s voucher', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]));
    $voucher = Voucher::sole();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);
    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $other->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.voucher.prepare', 'accounting.voucher.post']);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    $stranger->assignRole($role);

    $this->actingAs($stranger)->get("/accounting/vouchers/doc/{$voucher->id}")->assertNotFound();
    $this->actingAs($stranger)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", ['reason' => 'Not mine'])->assertNotFound();

    TenantScope::enter($this->business->id);
    expect($voucher->fresh()->status)->toBe(VoucherStatus::Posted);
});

it('is closed to someone with no accounting permission at all', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/accounting/vouchers/payment')->assertForbidden();
    $this->actingAs($nobody)->get('/accounting/vouchers/payment/create')->assertForbidden();
    $this->actingAs($nobody)->post('/accounting/vouchers/payment', ($this->payload)())->assertForbidden();
    $this->actingAs($this->reader)->post('/accounting/vouchers/payment', ($this->payload)())->assertForbidden();

    expect(Voucher::count())->toBe(0);
});

it('names each voucher by its own type in the day book', function () {
    $this->actingAs($this->owner)->post('/accounting/vouchers/payment', ($this->payload)(['post' => true]));

    $this->actingAs($this->reader)->get('/accounting/day-book')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journals.data.0.kind', 'Payment voucher')
            ->where('journals.data.0.document_number', 'PV-2026-27-0001')
            ->where('kinds', ['Payment voucher']));

    $this->actingAs($this->reader)->get('/accounting/day-book?kind='.urlencode('Payment voucher'))
        ->assertInertia(fn (Assert $page) => $page->where('count', 1));

    $this->actingAs($this->reader)->get('/accounting/day-book?kind='.urlencode('Receive voucher'))
        ->assertInertia(fn (Assert $page) => $page->where('count', 0));
});
