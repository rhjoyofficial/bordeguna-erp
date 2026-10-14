<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 3 (spec §12): the bank statement meets the book.
 *
 * The existing reconciliation screen proves the BOOK against ITSELF
 * (subledger vs GL); this is the other proof - the bank's own statement
 * against the book, line by line. Auto-matching is deliberately timid: exact
 * amount, right direction, within three days, exactly ONE candidate. An
 * ambiguous match is a human's call, because a wrong auto-match hides a real
 * discrepancy behind a false agreement.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Match Co', 'slug' => 'match-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.journal.post', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Matcher', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'accounting.journal.post']);
    Role::firstOrCreate(['name' => 'Looker', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->matcher = User::factory()->create();
    $this->matcher->businesses()->attach($this->business->id);
    $this->matcher->assignRole('Matcher');

    $this->looker = User::factory()->create();
    $this->looker->businesses()->attach($this->business->id);
    $this->looker->assignRole('Looker');

    $glAccount = Account::create(['code' => '1120', 'name' => 'Cash at Bank', 'type' => 'asset', 'is_postable' => true]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => $glAccount->id,
        'currency' => 'BDT', 'is_active' => true,
    ]);

    // A book line: 4,900 OUT of the bank on 31 Aug (the hosting payment).
    $this->journal = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id,
        'journal_number' => 'JV-M-0001', 'journal_type' => 'manual',
        'journal_date' => '2026-08-31', 'memo' => 'Hosting paid',
        'posted_by' => $this->matcher->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    $this->bookLine = DB::table('journal_lines')->insertGetId([
        'journal_id' => $this->journal, 'business_id' => $this->business->id,
        'line_no' => 1, 'account_id' => $glAccount->id,
        'amount_minor' => -490000, // credit the bank: money out
        'money_account_id' => $this->bank->id,
        'created_at' => now(),
    ]);
});

function asMatcher()
{
    return test()->actingAs(test()->matcher)->withSession(['current_business_id' => test()->business->id]);
}

function statementCsv(string $rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('statement.csv', "date,description,amount\n".$rows);
}

it('imports a statement and auto-matches the unambiguous line', function () {
    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv("2026-08-31,NIKOLA HOST PAYMENT,-4900\n2026-09-01,UNKNOWN DEPOSIT,10000"),
        ])
        ->assertSessionHasNoErrors();

    $lines = DB::table('bank_statement_lines')->orderBy('txn_date')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->match_status)->toBe('matched')
        ->and((int) $lines[0]->matched_journal_line_id)->toBe($this->bookLine)
        // The deposit has no book line at all - it stays unmatched, loudly.
        ->and($lines[1]->match_status)->toBe('unmatched');
});

it('reads a debit/credit statement the same way', function () {
    $file = UploadedFile::fake()->createWithContent('statement.csv',
        "date,description,debit,credit\n2026-08-31,NIKOLA HOST,4900,\n");

    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => $file,
        ])
        ->assertSessionHasNoErrors();

    // A DEBIT on the bank's statement is money OUT of the account.
    $line = DB::table('bank_statement_lines')->first();

    expect((float) $line->amount)->toBe(-4900.0)
        ->and($line->match_status)->toBe('matched');
});

it('leaves an ambiguous amount unmatched for a human', function () {
    // A second identical book payment in the window: two candidates.
    $journal = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id,
        'journal_number' => 'JV-M-0002', 'journal_type' => 'manual',
        'journal_date' => '2026-08-30', 'memo' => 'Another 4,900',
        'posted_by' => $this->matcher->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        'journal_id' => $journal, 'business_id' => $this->business->id,
        'line_no' => 1, 'account_id' => $this->bank->account_id,
        'amount_minor' => -490000, 'money_account_id' => $this->bank->id,
        'created_at' => now(),
    ]);

    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv('2026-08-31,NIKOLA HOST PAYMENT,-4900'),
        ]);

    expect(DB::table('bank_statement_lines')->value('match_status'))->toBe('unmatched');
});

it('lets a human match, unmatch and ignore - and never a viewer', function () {
    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv('2026-09-01,BANK CHARGE,-115'),
        ]);

    $lineId = DB::table('bank_statement_lines')->value('id');

    // Manual match against the (wrong-amount) book line is refused.
    asMatcher()
        ->post(route('accounting.bank-statements.match', $lineId), ['journal_line_id' => $this->bookLine])
        ->assertSessionHasErrors('journal_line_id');

    // Ignore instead (a bank charge not yet in the book).
    asMatcher()
        ->post(route('accounting.bank-statements.ignore', $lineId))
        ->assertSessionHasNoErrors();

    expect(DB::table('bank_statement_lines')->where('id', $lineId)->value('match_status'))->toBe('ignored');

    // A viewer can look at the screen but never act.
    test()->actingAs(test()->looker)->withSession(['current_business_id' => test()->business->id])
        ->post(route('accounting.bank-statements.ignore', $lineId))
        ->assertForbidden();
});

it('shows the screen with both sides: statement lines and unmatched book lines', function () {
    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv('2026-09-01,UNKNOWN DEPOSIT,10000'),
        ]);

    asMatcher()
        ->get(route('accounting.bank-statements', ['money_account_id' => $this->bank->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/bank-statements')
            ->has('statement_lines', 1)
            // The 4,900 book payment has no statement line: the other side
            // of the same truth, shown beside it.
            ->has('unmatched_book_lines', 1)
            ->where('can_act', true));
});

// Audit #11, 14 Oct 2026: one claim per book line.
it('refuses a second statement line claiming a book line that is already matched', function () {
    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv('2026-08-31,NIKOLA HOST PAYMENT,-4900'),
        ])
        ->assertSessionHasNoErrors();

    // A second row for the same money, from a different file: auto-match
    // leaves it alone (the only candidate is taken), and a human cannot force it.
    asMatcher()
        ->post(route('accounting.bank-statements.import'), [
            'money_account_id' => $this->bank->id,
            'file' => statementCsv('2026-09-01,NIKOLA HOST AGAIN,-4900'),
        ])
        ->assertSessionHasNoErrors();

    $second = DB::table('bank_statement_lines')->orderByDesc('id')->first();
    expect($second->match_status)->toBe('unmatched');

    asMatcher()
        ->post(route('accounting.bank-statements.match', $second->id), ['journal_line_id' => $this->bookLine])
        ->assertSessionHasErrors(['journal_line_id' => 'That book line is already matched to the statement line of 2026-08-31 (NIKOLA HOST PAYMENT). Unmatch that one first.']);

    expect(DB::table('bank_statement_lines')->where('matched_journal_line_id', $this->bookLine)->count())->toBe(1);
});

it('has a unique index under the claim, so a race cannot double-claim either', function () {
    asMatcher()->post(route('accounting.bank-statements.import'), [
        'money_account_id' => $this->bank->id,
        'file' => statementCsv("2026-08-31,ONE,-4900\n2026-09-02,TWO,-4900"),
    ]);

    $unmatched = DB::table('bank_statement_lines')->where('match_status', 'unmatched')->value('id');

    expect(fn () => DB::table('bank_statement_lines')->where('id', $unmatched)->update(['matched_journal_line_id' => $this->bookLine]))
        ->toThrow(UniqueConstraintViolationException::class);
});

// Audit #11, 14 Oct 2026: the same file twice.
it('refuses the same statement file imported twice to the same account, even renamed', function () {
    $csv = "date,description,amount\n2026-08-31,NIKOLA HOST PAYMENT,-4900\n2026-09-01,UNKNOWN DEPOSIT,10000";

    asMatcher()->post(route('accounting.bank-statements.import'), [
        'money_account_id' => $this->bank->id,
        'file' => UploadedFile::fake()->createWithContent('september.csv', $csv),
    ])->assertSessionHasNoErrors();

    asMatcher()->post(route('accounting.bank-statements.import'), [
        'money_account_id' => $this->bank->id,
        'file' => UploadedFile::fake()->createWithContent('september (1).csv', $csv),
    ])->assertSessionHasErrors('file');

    expect(DB::table('bank_statement_lines')->count())->toBe(2)
        ->and(DB::table('bank_statement_imports')->count())->toBe(1)
        ->and(DB::table('bank_statement_imports')->value('file_sha256'))->toBe(hash('sha256', $csv));
});
