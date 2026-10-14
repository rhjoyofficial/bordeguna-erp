<?php

use App\Modules\Accounting\Support\BankStatementFile;
use Illuminate\Support\Carbon;

/**
 * How a bank's CSV is read (14 Oct 2026 audit).
 *
 * FEA-001: `03/10/2026` was read the American way, 10 March, so any day from
 * 1 to 12 was silently swapped and a 13th refused the file.
 * FEA-002: `(150.00)` and `BDT 2500` were read as 0 and dropped, and
 * `1500.00 Dr` came in as money IN.
 */
beforeEach(fn () => Carbon::setTestNow('2026-10-14 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function statementFile(string $csv): string
{
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, $csv);

    return $path;
}

it('reads slash dates day first, as Bangladeshi banks write them, and says so (FEA-001)', function () {
    $parsed = BankStatementFile::parse(statementFile("Date,Particulars,Debit,Credit\n03/10/2026,Hosting,4900.00,\n12/09/2026,Deposit,,100\n"));

    expect(collect($parsed['rows'])->pluck('txn_date')->all())->toBe(['2026-10-03', '2026-09-12'])
        ->and($parsed['date_format'])->toBe(BankStatementFile::DAY_FIRST)
        ->and($parsed['date_guessed'])->toBeTrue();
});

it('reads a 13th as day first too, and then the whole column is certain (FEA-001)', function () {
    $parsed = BankStatementFile::parse(statementFile("Date,Particulars,Amount\n03/10/2026,A,-10\n13/10/2026,B,20\n"));

    expect(collect($parsed['rows'])->pluck('txn_date')->all())->toBe(['2026-10-03', '2026-10-13'])
        ->and($parsed['date_guessed'])->toBeFalse();
});

it('reads a column that proves it is month first as month first, and says so (FEA-001)', function () {
    $parsed = BankStatementFile::parse(statementFile("Date,Particulars,Amount\n10/03/2026,A,-10\n10/13/2026,B,20\n"));

    expect(collect($parsed['rows'])->pluck('txn_date')->all())->toBe(['2026-10-03', '2026-10-13'])
        ->and($parsed['date_format'])->toBe(BankStatementFile::MONTH_FIRST);
});

it('refuses a column whose dates disagree, and a date after today (FEA-001)', function () {
    expect(fn () => BankStatementFile::parse(statementFile("Date,Particulars,Amount\n13/10/2026,A,-10\n10/13/2026,B,20\n")))
        ->toThrow(RuntimeException::class, 'The dates disagree');

    expect(fn () => BankStatementFile::parse(statementFile("Date,Particulars,Amount\n20/10/2026,A,-10\n")))
        ->toThrow(RuntimeException::class, 'after today');
});

it('reads month names and ISO dates without asking (FEA-001)', function () {
    $parsed = BankStatementFile::parse(statementFile("Date,Particulars,Amount\n03-Oct-2026,A,-10\n2026-09-12,B,20\n\"Oct 1, 2026\",C,5\n"));

    expect(collect($parsed['rows'])->pluck('txn_date')->all())->toBe(['2026-10-03', '2026-09-12', '2026-10-01'])
        ->and($parsed['date_format'])->toBe(BankStatementFile::UNAMBIGUOUS);
});

it('reads brackets, currency words, Dr and Cr with the right sign (FEA-002)', function () {
    $rows = BankStatementFile::read(statementFile(
        "Date,Description,Amount\n2026-10-03,Fee,(150.00)\n2026-10-04,Deposit,BDT 2500\n2026-10-05,Out,\"1,500.00 Dr\"\n2026-10-06,In,\"1,500.00 Cr\"\n2026-10-07,Tk,Tk. 75\n2026-10-08,ok,-10\n2026-10-09,zero,0.00\n2026-10-10,empty,\n",
    ));

    expect(collect($rows)->pluck('amount')->all())->toBe([-150.0, 2500.0, -1500.0, 1500.0, 75.0, -10.0]);
});

it('refuses a line it cannot read instead of skipping it (FEA-002)', function () {
    expect(fn () => BankStatementFile::read(statementFile("Date,Description,Amount\n2026-10-03,Fee,about 150\n")))
        ->toThrow(RuntimeException::class, 'Line 2: unreadable amount');

    expect(fn () => BankStatementFile::read(statementFile("Date,Description,Debit,Credit\n2026-10-03,Fee,150 Cr,\n")))
        ->toThrow(RuntimeException::class, 'debit column');
});

it('reads a debit and credit pair with currency words in each (FEA-002)', function () {
    $rows = BankStatementFile::read(statementFile("Date,Particulars,Withdrawal,Deposit\n02/10/2026,Fee,\"BDT 1,200.50\",\n01/10/2026,In,,Tk 300\n"));

    expect(collect($rows)->pluck('amount')->all())->toBe([-1200.5, 300.0])
        ->and(collect($rows)->pluck('txn_date')->all())->toBe(['2026-10-02', '2026-10-01']);
});
