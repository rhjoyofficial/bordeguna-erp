<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
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

it('offers the form only accounts a voucher may use, and marks the party-enabled ones', function () {
    $this->actingAs($this->finance)->get('/accounting/vouchers/payment/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/vouchers/form')
            ->where('type.value', 'payment')
            ->where('type.fixed_side', 'debit')
            ->where('moneyAccounts.0.label', 'BRAC Bank')
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('label')->contains('6051 - Office Rent')
                // cash and bank heads move through the money account field
                && ! collect($accounts)->pluck('label')->contains('1120 - Cash at Bank')
                && ! collect($accounts)->pluck('label')->contains('3900 - Opening Balance Equity')
                && collect($accounts)->firstWhere('label', '1160 - Advances to Suppliers')['party'] === 'supplier'
                && collect($accounts)->firstWhere('label', '2150 - Advances from Customers')['party'] === 'customer'
                && collect($accounts)->firstWhere('label', '6051 - Office Rent')['party'] === null));
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
