<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\ExpenseVoucherLine;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The expense register as a file somebody can open in Excel.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. The export shipped with a button on the screen, a
 * route, a controller and a formatter, and not one test. A download is the
 * easiest thing in the system to break silently: it renders nowhere, nobody
 * reads it until month end, and by then the month it was wrong about is closed.
 *
 * ⚠ The assertions are on the CONTENT of the file, not on a 200. A response
 * that streams an empty body is a 200 too.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-29 10:00:00');

    $this->business = Business::create(['name' => 'Export Co', 'slug' => 'export-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Export Co', 'slug' => 'rival-export-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    TenantScope::enter($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->rent = Account::where('code', '6051')->sole();
    $this->fuel = Account::where('code', '6011')->sole();

    /*
     * A voucher written straight to the table. The ledger is not what this file
     * reads, and posting each one through the action would tie the test to the
     * accounting period being open in every month it names.
     */
    $this->voucher = function (string $number, string $spentOn, array $lines, array $overrides = [], ?int $businessId = null) {
        $businessId ??= $this->business->id;

        $voucher = ExpenseVoucher::withoutGlobalScopes()->create(array_replace([
            'business_id' => $businessId,
            'voucher_number' => $number,
            'spent_on' => $spentOn,
            'money_account_id' => $this->bank->id,
            'paid_to' => 'Adabor landlord',
            'total' => array_sum(array_map('floatval', array_column($lines, 'amount'))),
            'reference' => 'RCPT-'.$number,
            'created_by' => $this->owner->id,
        ], $overrides));

        foreach ($lines as $line) {
            ExpenseVoucherLine::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'expense_voucher_id' => $voucher->id,
                'account_id' => $line['account_id'],
                'description' => $line['description'],
                'amount' => $line['amount'],
            ]);
        }

        return $voucher;
    };

    /* Read the streamed file back as rows, header first. */
    $this->rowsOf = function ($response): array {
        $body = ltrim($response->streamedContent(), "\u{FEFF}");

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    };
});

afterEach(fn () => Carbon::setTestNow());

/**
 * ⚠⚠ THE REACHABILITY TEST. Not "does the formatter work" but "can a person
 * standing on the expenses screen actually get the file".
 */
it('downloads the expense register as a csv the screen can link to', function () {
    ($this->voucher)('EXP-00001', '2026-08-04', [
        ['account_id' => $this->rent->id, 'description' => 'August rent', 'amount' => '25000.00'],
    ]);

    $response = $this->actingAs($this->owner)->get('/purchasing/expenses/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))->toContain('attachment');

    $rows = ($this->rowsOf)($response);

    expect($rows[0])->toBe([
        'voucher_number', 'spent_on', 'payee', 'paid_from', 'expense_account',
        'description', 'amount', 'voucher_total', 'reference', 'note', 'receipts', 'recorded_by',
    ]);
    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe('EXP-00001')
        ->and($rows[1][1])->toBe('2026-08-04')
        ->and($rows[1][2])->toBe('Adabor landlord')
        ->and($rows[1][3])->toBe('BRAC Bank - Current')
        ->and($rows[1][6])->toBe('25000');
});

/**
 * ⚠⚠ The rule the whole file rests on. A voucher covering rent and fuel is two
 * amounts charged to two heads; collapsing it to one row would give a file that
 * agrees with the bank and disagrees with the accounts.
 */
it('writes one row per line, repeating the voucher number, so the amounts add up to the accounts', function () {
    ($this->voucher)('EXP-00001', '2026-08-04', [
        ['account_id' => $this->rent->id, 'description' => 'August rent', 'amount' => '25000.00'],
        ['account_id' => $this->fuel->id, 'description' => 'Delivery van', 'amount' => '1500.50'],
    ]);

    $rows = ($this->rowsOf)($this->actingAs($this->owner)->get('/purchasing/expenses/export'));
    $body = array_slice($rows, 1);

    expect($body)->toHaveCount(2);
    expect(array_column($body, 0))->toBe(['EXP-00001', 'EXP-00001']);

    // `amount` is the column that adds up.
    expect(array_sum(array_map('floatval', array_column($body, 6))))->toBe(26500.5);

    // ⚠ `voucher_total` repeats and must NOT be summed. Pinning it here is what
    // stops somebody "fixing" the duplication by blanking it on later lines.
    expect(array_column($body, 7))->toBe(['26500.5', '26500.5']);

    expect(array_column($body, 4))->toBe([
        $this->rent->label(),
        $this->fuel->label(),
    ]);
});

it('gives only the months asked for, and everything when asked for nothing', function () {
    ($this->voucher)('EXP-00001', '2026-06-30', [
        ['account_id' => $this->rent->id, 'description' => 'June rent', 'amount' => '1.00'],
    ]);
    ($this->voucher)('EXP-00002', '2026-07-01', [
        ['account_id' => $this->rent->id, 'description' => 'July rent', 'amount' => '2.00'],
    ]);
    ($this->voucher)('EXP-00003', '2026-07-31', [
        ['account_id' => $this->rent->id, 'description' => 'End of July', 'amount' => '3.00'],
    ]);
    ($this->voucher)('EXP-00004', '2026-08-01', [
        ['account_id' => $this->rent->id, 'description' => 'August rent', 'amount' => '4.00'],
    ]);

    $july = ($this->rowsOf)(
        $this->actingAs($this->owner)->get('/purchasing/expenses/export?from=2026-07-01&to=2026-07-31')
    );

    // ⚠ Both ends INCLUSIVE. A July file that dropped the 31st would understate
    // the month by a day and nobody would see it.
    expect(array_column(array_slice($july, 1), 0))->toBe(['EXP-00002', 'EXP-00003']);

    $everything = ($this->rowsOf)($this->actingAs($this->owner)->get('/purchasing/expenses/export'));

    expect(array_column(array_slice($everything, 1), 0))
        ->toBe(['EXP-00001', 'EXP-00002', 'EXP-00003', 'EXP-00004']);
});

it('refuses a date range that ends before it starts', function () {
    $this->actingAs($this->owner)
        ->get('/purchasing/expenses/export?from=2026-08-01&to=2026-07-01')
        ->assertSessionHasErrors('to');
});

/**
 * ⚠ Money left the account whether or not anybody explained it. A voucher with
 * no lines vanishing from the file is exactly how an unexplained payment stays
 * unexplained.
 */
it('still shows a voucher nobody gave a reason for', function () {
    ($this->voucher)('EXP-00001', '2026-08-04', [], ['total' => '900.00', 'paid_to' => 'Unknown']);

    $rows = ($this->rowsOf)($this->actingAs($this->owner)->get('/purchasing/expenses/export'));

    expect($rows)->toHaveCount(2);
    expect($rows[1][0])->toBe('EXP-00001')
        ->and($rows[1][4])->toBe('')
        ->and($rows[1][6])->toBe('')
        ->and($rows[1][7])->toBe('900');
});

/**
 * ⚠⚠ THE TENANT TEST, AND IT IS NOT DECORATION. `streamDownload` defers its
 * callback until after the response is returned, so a query written outside the
 * closure would run with the scope already gone and hand one business the other
 * one's spending.
 */
it('never lets one business read another business spending', function () {
    ($this->voucher)('EXP-00001', '2026-08-04', [
        ['account_id' => $this->rent->id, 'description' => 'Our rent', 'amount' => '10.00'],
    ]);
    ($this->voucher)('RIV-00001', '2026-08-04', [
        ['account_id' => $this->rent->id, 'description' => 'Their rent', 'amount' => '999.00'],
    ], ['paid_to' => 'Rival landlord'], $this->other->id);

    $rows = ($this->rowsOf)($this->actingAs($this->owner)->get('/purchasing/expenses/export'));

    expect(array_column(array_slice($rows, 1), 0))->toBe(['EXP-00001']);
    expect(implode('', array_map(fn ($r) => implode('', $r), $rows)))->not->toContain('Rival landlord');
});

it('opens in Excel with Bangla intact', function () {
    $payee = "\u{0986}\u{09A6}\u{09BE}\u{09AC}\u{09B0} \u{09AC}\u{09BE}\u{09A1}\u{09BC}\u{09BF}\u{0993}\u{09AF}\u{09BC}\u{09BE}\u{09B2}\u{09BE}";
    $reason = "\u{0985}\u{0997}\u{09BE}\u{09B8}\u{09CD}\u{099F} \u{09AE}\u{09BE}\u{09B8}\u{09C7}\u{09B0} \u{09AD}\u{09BE}\u{09A1}\u{09BC}\u{09BE}";

    ($this->voucher)('EXP-00001', '2026-08-04', [
        ['account_id' => $this->rent->id, 'description' => $reason, 'amount' => '25000.00'],
    ], ['paid_to' => $payee]);

    $body = $this->actingAs($this->owner)->get('/purchasing/expenses/export')->streamedContent();

    // ⚠⚠ Without the BOM Excel opens this in the system codepage and every
    // Bangla name becomes mojibake, which somebody then "corrects" by retyping.
    expect($body)->toStartWith("\u{FEFF}");
    expect($body)->toContain($payee)
        ->and($body)->toContain($reason);
});

it('refuses the file to somebody who may not read what the business spent', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);

    $this->actingAs($outsider)->get('/purchasing/expenses/export')->assertForbidden();
});

it('names the file after the range it covers', function () {
    $named = $this->actingAs($this->owner)->get('/purchasing/expenses/export?from=2026-07-01&to=2026-07-31');
    expect($named->headers->get('content-disposition'))->toContain('expenses-2026-07-01-to-2026-07-31.csv');

    $unnamed = $this->actingAs($this->owner)->get('/purchasing/expenses/export');
    expect($unnamed->headers->get('content-disposition'))->toContain('expenses-2026-08-29.csv');
});
