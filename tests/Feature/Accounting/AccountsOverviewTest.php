<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountsOverview;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Accounts > Overview (redesign round 3, 16 Oct 2026, D10): the three reads
 * no report produced before (AccountsOverview), each scoped to the business
 * the request stands in, and the page that shows them to `accounting.view`
 * holders only.
 *
 * ⚠ The verifier's corrections are what these tests pin down:
 * - a cheque waiting to clear is a POSTED cheque voucher with NO
 *   cheque_statuses row (the table has no "pending" status);
 * - a journal draft balances on `side` + `amount_minor` (there are no debit
 *   and credit columns).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 10:00:00');

    $this->business = Business::create(['name' => 'Overview Co', 'slug' => 'overview-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Elsewhere Co', 'slug' => 'elsewhere-co']);

    foreach ([$this->other, $this->business] as $business) {
        foreach (['Owner', 'Finance', 'Payroll', 'Manager', 'Staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);
        }
    }

    TenantScope::enter($this->business->id);
    $this->seed(AccountingPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->as = function (string $role): User {
        $user = User::factory()->create(['name' => $role.' person']);
        $user->businesses()->attach($this->business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $this->business->id)->firstOrFail());

        return $user;
    };

    $this->owner = ($this->as)('Owner');
    $this->rent = Account::where('code', '6051')->sole();
    $this->bankHead = Account::bySlug('cash_at_bank');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => $this->bankHead->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A voucher written straight to the table: these tests are about reading
 * vouchers, and the engine that writes them is tested on its own.
 *
 * @param  list<array{0: int, 1: string, 2: int}>  $lines  [account id, side, paisa]
 */
function aovVoucher(int $businessId, int $userId, array $attributes = [], array $lines = []): int
{
    $id = DB::table('vouchers')->insertGetId($attributes + [
        'business_id' => $businessId,
        'type' => 'payment',
        'status' => 'draft',
        'voucher_date' => '2026-10-10',
        'total_minor' => 0,
        'created_by' => $userId,
        'created_at' => '2026-10-10 09:00:00',
        'updated_at' => '2026-10-10 09:00:00',
    ]);

    foreach ($lines as $index => [$accountId, $side, $minor]) {
        DB::table('voucher_lines')->insert([
            'voucher_id' => $id, 'business_id' => $businessId, 'line_no' => $index + 1,
            'account_id' => $accountId, 'side' => $side, 'amount_minor' => $minor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

it('counts vouchers by type and status for the range, and what is still open whatever its date', function () {
    $u = $this->owner->id;
    $b = $this->business->id;

    aovVoucher($b, $u, ['status' => 'posted', 'total_minor' => 1_000_00, 'voucher_number' => 'PV-1', 'posted_at' => '2026-10-16 09:00:00']);
    aovVoucher($b, $u, ['status' => 'posted', 'total_minor' => 2_500_00, 'voucher_number' => 'PV-2', 'posted_at' => '2026-10-12 09:00:00']);
    aovVoucher($b, $u, ['status' => 'draft', 'voucher_date' => '2026-10-14']);
    aovVoucher($b, $u, ['status' => 'pending_approval', 'total_minor' => 90_000_00]);
    aovVoucher($b, $u, ['type' => 'receipt', 'status' => 'reversed', 'total_minor' => 300_00, 'voucher_number' => 'RV-1']);
    aovVoucher($b, $u, ['type' => 'journal', 'status' => 'draft', 'created_at' => '2026-10-16 08:00:00']);
    // Before the range: not in the table, still an open draft (the oldest).
    aovVoucher($b, $u, ['type' => 'contra', 'status' => 'draft', 'voucher_date' => '2026-09-20']);
    aovVoucher($b, $u, ['status' => 'posted', 'voucher_date' => '2026-09-30', 'total_minor' => 7_00, 'voucher_number' => 'PV-0']);

    // Another business's vouchers are never counted.
    $stranger = User::factory()->create();
    aovVoucher($this->other->id, $stranger->id, ['status' => 'posted', 'total_minor' => 5_000_00, 'voucher_number' => 'PV-X', 'posted_at' => '2026-10-16 09:30:00', 'created_at' => '2026-10-16 09:30:00']);
    aovVoucher($this->other->id, $stranger->id, ['status' => 'draft']);

    // ⚠ Recorded through the real action (17 Oct 2026): other income is now
    // read from the LEDGER (live vouchers counted, money from their journals),
    // so a row written straight to the table with no journal adds nothing.
    app(RecordIncomeVoucher::class)->handle(
        moneyAccount: $this->bank,
        receivedOn: '2026-10-05',
        lines: [['account' => Account::where('code', '4910')->sole(), 'amount' => '475.50']],
        createdBy: $this->owner,
        receivedFrom: 'Scrap dealer',
    );

    $counts = (new AccountsOverview($b))->voucherCounts(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-16')->endOfDay(), Carbon::parse('2026-10-16'));
    $byType = collect($counts['rows'])->keyBy('type');

    expect($byType->keys()->all())->toBe(['payment', 'receipt', 'contra', 'journal'])
        ->and($byType['payment'])->toMatchArray(['posted' => 2, 'draft' => 1, 'pending_approval' => 1, 'reversed' => 0, 'posted_minor' => 3_500_00])
        ->and($byType['receipt'])->toMatchArray(['posted' => 0, 'reversed' => 1, 'posted_minor' => 0])
        ->and($byType['journal'])->toMatchArray(['draft' => 1])
        ->and($byType['contra'])->toMatchArray(['draft' => 0])
        ->and($counts['other_income'])->toBe(['count' => 1, 'minor' => 475_50])
        ->and($counts['open'])->toMatchArray([
            'draft' => 3,
            'pending_approval' => 1,
            'oldest_draft' => '2026-09-20',
            'pending_by_type' => ['payment' => 1],
        ])
        ->and($counts['open']['drafts_by_type'])->toEqualCanonicalizing(['payment' => 1, 'journal' => 1, 'contra' => 1])
        // Entered today: the journal draft and the income voucher. Posted today: PV-1.
        ->and($counts['today'])->toBe(['entered' => 2, 'posted' => 1]);
});

it('finds journal drafts that do not balance by side and amount, and nothing else', function () {
    $u = $this->owner->id;
    $b = $this->business->id;
    $rent = $this->rent->id;
    $bank = $this->bankHead->id;

    $balanced = aovVoucher($b, $u, ['type' => 'journal'], [[$rent, 'debit', 500_00], [$bank, 'credit', 500_00]]);
    $short = aovVoucher($b, $u, ['type' => 'journal'], [[$rent, 'debit', 1_700_00], [$bank, 'credit', 500_00]]);
    $creditHeavy = aovVoucher($b, $u, ['type' => 'journal'], [[$rent, 'debit', 100_00], [$bank, 'credit', 400_00]]);
    // Posted journals balanced when they were posted; a payment draft has one side by design.
    aovVoucher($b, $u, ['type' => 'journal', 'status' => 'posted', 'voucher_number' => 'JV-9'], [[$rent, 'debit', 900_00]]);
    aovVoucher($b, $u, ['type' => 'payment'], [[$rent, 'debit', 900_00]]);
    // Somebody else's books.
    aovVoucher($this->other->id, User::factory()->create()->id, ['type' => 'journal'], [[$rent, 'debit', 50_00]]);

    $drafts = (new AccountsOverview($b))->unbalancedJournalDrafts();

    expect(array_column($drafts, 'id'))->toBe([$short, $creditHeavy])
        ->and($drafts[0])->toMatchArray(['difference_minor' => 1_200_00, 'created_by' => 'Owner person', 'title' => 'Draft journal voucher'])
        ->and($drafts[1]['difference_minor'])->toBe(-300_00)
        ->and(array_column($drafts, 'id'))->not->toContain($balanced);
});

it('counts a posted cheque with no status row as waiting, and those past their date', function () {
    $u = $this->owner->id;
    $b = $this->business->id;
    $cheque = fn (array $more) => aovVoucher($b, $u, $more + ['instrument_type' => 'cheque', 'status' => 'posted', 'voucher_number' => 'PV-'.uniqid()]);

    $cheque(['total_minor' => 10_000_00, 'instrument_date' => '2026-10-10']);
    $cheque(['total_minor' => 2_000_00, 'instrument_date' => '2026-10-20', 'type' => 'receipt']);
    $cheque(['total_minor' => 1_00, 'instrument_date' => null]);
    $cleared = $cheque(['total_minor' => 50_000_00, 'instrument_date' => '2026-10-01']);
    DB::table('cheque_statuses')->insert([
        'business_id' => $b, 'voucher_id' => $cleared, 'status' => 'cleared', 'status_on' => '2026-10-03',
        'updated_by' => $u, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // A draft cheque is not written yet; a bank transfer is not a cheque.
    aovVoucher($b, $u, ['instrument_type' => 'cheque', 'status' => 'draft', 'total_minor' => 8_00, 'instrument_date' => '2026-10-01']);
    aovVoucher($b, $u, ['instrument_type' => 'bank_transfer', 'status' => 'posted', 'voucher_number' => 'PV-T', 'instrument_date' => '2026-10-01']);
    // Another business's cheque.
    aovVoucher($this->other->id, User::factory()->create()->id, ['instrument_type' => 'cheque', 'status' => 'posted', 'voucher_number' => 'PV-O', 'total_minor' => 9_00, 'instrument_date' => '2026-10-01']);

    expect((new AccountsOverview($b))->chequesWaiting(Carbon::parse('2026-10-16')))
        ->toBe(['count' => 3, 'past_date' => 1, 'minor' => 12_001_00, 'oldest' => '2026-10-10']);
});

it('puts the three new reads on the Overview, as lines of Needs attention for the business in hand', function () {
    $u = $this->owner->id;
    $b = $this->business->id;

    aovVoucher($b, $u, ['type' => 'journal'], [[$this->rent->id, 'debit', 1_200_00]]);
    aovVoucher($b, $u, ['instrument_type' => 'cheque', 'status' => 'posted', 'voucher_number' => 'PV-C', 'total_minor' => 62_000_00, 'instrument_date' => '2026-10-01']);
    aovVoucher($b, $u, ['status' => 'pending_approval', 'total_minor' => 80_000_00]);
    aovVoucher($this->other->id, User::factory()->create()->id, ['type' => 'journal'], [[$this->rent->id, 'debit', 5_00]]);

    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/finance-dashboard')
            ->where('range.key', 'this_month')
            ->where('range.from', '2026-10-01')
            ->where('range.to', '2026-10-16')
            ->where('vouchers.rows.0.type', 'payment')
            ->where('vouchers.rows.0.posted', 1)
            ->where('vouchers.rows.0.pending_approval', 1)
            ->where('vouchers.rows.0.posted_amount', fn ($v) => (float) $v === 62000.0)
            ->where('attention', function ($items) {
                $byKey = collect($items)->keyBy('key');

                return $byKey->has(['unbalanced', 'cheques', 'approvals', 'drafts'])
                    && $byKey['unbalanced']['count'] === 1
                    && str_contains($byKey['unbalanced']['hint'], 'debits ahead by 1,200.00')
                    && $byKey['cheques']['count'] === 1
                    && str_contains($byKey['cheques']['hint'], '1 past its date')
                    && $byKey['approvals']['url'] === '/accounting/vouchers/payment?status=pending_approval'
                    // Drafts: the one journal of THIS business only.
                    && $byKey['drafts']['count'] === 1
                    && ! $byKey->has('loans_due') && ! $byKey->has('loans_overdue');
            })
            ->where('can', ['prepare_voucher' => true, 'record_income' => true, 'view_loans' => true])
        );
});

it('follows the range the person picks, and only the three it offers', function () {
    $this->actingAs($this->owner)->get('/accounting/overview?range=last_month')
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.key', 'last_month')
            ->where('range.from', '2026-09-01')
            ->where('range.to', '2026-09-30')
            ->where('range.label', 'September 2026'));

    // The fiscal year starts in July for this business.
    $this->actingAs($this->owner)->get('/accounting/overview?range=this_year')
        ->assertInertia(fn (Assert $page) => $page->where('range.key', 'this_year')->where('range.from', '2026-07-01'));

    $this->actingAs($this->owner)->get('/accounting/overview?range=decade')
        ->assertInertia(fn (Assert $page) => $page->where('range.key', 'this_month'));
});

it('opens to the roles that read the books, and offers each button only to whoever may press it', function () {
    $this->actingAs(($this->as)('Finance'))->get('/accounting/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can', ['prepare_voucher' => true, 'record_income' => true, 'view_loans' => true]));

    // Payroll reads the books and records nothing; no loans either.
    $this->actingAs(($this->as)('Payroll'))->get('/accounting/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can', ['prepare_voucher' => false, 'record_income' => false, 'view_loans' => false])
            ->where('attention', fn ($items) => collect($items)->every(fn ($item) => ! str_starts_with($item['key'], 'loans'))));

    // The Manager reads loans and counterparties, not the ledger: no Overview.
    $this->actingAs(($this->as)('Manager'))->get('/accounting/overview')->assertForbidden();
    $this->actingAs(($this->as)('Staff'))->get('/accounting/overview')->assertForbidden();
});

it('counts the vouchers list by status for the chips, inside the type, the dates and the search', function () {
    $u = $this->owner->id;
    $b = $this->business->id;

    aovVoucher($b, $u, ['status' => 'draft', 'counterparty' => 'Landlord']);
    aovVoucher($b, $u, ['status' => 'draft', 'counterparty' => 'Grocer']);
    aovVoucher($b, $u, ['status' => 'posted', 'voucher_number' => 'PV-1', 'counterparty' => 'Landlord']);
    aovVoucher($b, $u, ['status' => 'posted', 'voucher_number' => 'PV-2', 'counterparty' => 'Landlord', 'voucher_date' => '2026-08-01']);
    aovVoucher($b, $u, ['type' => 'receipt', 'status' => 'draft', 'counterparty' => 'Landlord']);
    aovVoucher($this->other->id, User::factory()->create()->id, ['status' => 'draft', 'counterparty' => 'Landlord']);

    $this->actingAs($this->owner)->get('/accounting/vouchers/payment?status=draft')
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts', ['all' => 4, 'draft' => 2, 'pending_approval' => 0, 'posted' => 2, 'reversed' => 0])
            ->where('vouchers.total', 2));

    $this->actingAs($this->owner)->get('/accounting/vouchers/payment?search=Landlord&from=2026-10-01')
        ->assertInertia(fn (Assert $page) => $page->where('statusCounts', ['all' => 2, 'draft' => 1, 'pending_approval' => 0, 'posted' => 1, 'reversed' => 0]));
});

it('lights the voucher type tab on a voucher page, which its address cannot', function () {
    $receipt = aovVoucher($this->business->id, $this->owner->id, ['type' => 'receipt', 'status' => 'draft']);
    $journal = aovVoucher($this->business->id, $this->owner->id, ['type' => 'journal', 'status' => 'draft']);

    $this->actingAs($this->owner)->get("/accounting/vouchers/doc/{$receipt}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sectionTab', '/accounting/vouchers/receipt'));

    $this->actingAs($this->owner)->get("/accounting/vouchers/doc/{$journal}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sectionTab', '/accounting/vouchers/journal'));
});
