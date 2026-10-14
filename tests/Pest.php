<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * SQL text with MySQL's backtick identifier quoting turned into SQLite's double
 * quotes, so a test that matches the text of a query (`from "journals"`) holds
 * on both databases. 14 Oct 2026: the MySQL CI job runs the same suite.
 */
function sqlText(string $sql): string
{
    return str_replace('`', '"', $sql);
}

/**
 * A response body with its database ids renumbered to what they are in a fresh
 * database, where each table's first row of the test is 1.
 *
 * ⚠ The HQ contract fixtures carry real ids, and HQ pins their digest. SQLite
 * rolls each test's auto-increment back with its transaction, so the ids start
 * at 1 in every test; MySQL never hands an id out twice, so the same test sees
 * ids that depend on every test run before it. Shifting each table down by its
 * lowest id in this test gives the same body on both, and every id is still
 * compared. `$tables` maps a dotted path (`[]` for any list item) to its table.
 *
 * @param  array<string, string>  $tables
 */
function freshIds(array $body, array $tables, string $path = ''): array
{
    foreach ($body as $key => $value) {
        $here = ltrim($path.'.'.(is_int($key) ? '[]' : $key), '.');

        if (is_array($value)) {
            $body[$key] = freshIds($value, $tables, $here);
        } elseif (is_int($value) && isset($tables[$here])) {
            $body[$key] = $value - freshIdOffset($tables[$here]);
        }
    }

    return $body;
}

/** How far a table's ids in this test sit above a fresh database's (0 on SQLite). */
function freshIdOffset(string $table): int
{
    return (int) DB::table($table)->min('id') - 1;
}

/**
 * An array with every associative level sorted by key, lists left in order.
 *
 * ⚠ A MySQL JSON column hands an object back with its keys re-ordered (shortest
 * first); SQLite keeps them as written. Neither order means anything, so a
 * test comparing a stored JSON object compares both sides through this, and
 * `toBe` still checks every value and its type.
 */
function canonicalJson(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(canonicalJson(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

/**
 * Approve a request only if it is still waiting for a decision.
 *
 * ⚠⚠ WHY THIS EXISTS. Several actions self-approve when the creator already
 * holds the approve permission - CreatePurchaseOrder does, and most test users
 * are Owners - so the object handed back is already Approved. Around thirty
 * test setups then called `approve()` on it a second time. That used to be
 * silent: a duplicate row in `approval_decisions` and a reset `reviewed_at`,
 * with nothing to notice. The concurrency guard added on 2026-08-23 refuses it,
 * which is how the double approval was found.
 *
 * ⚠ This is a TEST convenience only. Production never needs it: every approve
 * action already checks `isPending()` first, which is exactly what this does.
 */
function approveIfPending(
    ApprovalRequest $request,
    User $user,
    ?string $note = null,
): void {
    $current = $request->fresh();

    if ($current !== null && $current->isPending()) {
        $current->approve($user, $note);
    }
}

/**
 * A cash, bank or wallet head the CURRENT business can post a plain line to.
 *
 * ⚠ Since 21 Sep 2026 each such head belongs to one Bank & cash account and is
 * posted only through it (PostJournal stamps a plain line with its owner and
 * refuses a head nobody in this business owns). A test that uses the seeded
 * 11201 as "the bank" therefore gives its business a money account on it first,
 * which is exactly what a real installation has.
 */
/**
 * Opens a shift for `$user` on `$till`, or returns the one already open.
 *
 * ⭐ 26 Sep 2026 (Joy, Q3): cash at the counter goes into the cashier's OWN
 * open till or the sale is refused. A test that sells for cash therefore opens
 * a shift first, the way a real cashier does at the start of the day.
 */
function openTill(User $user, MoneyAccount $till): int
{
    $existing = DB::table('pos_shifts')
        ->where('money_account_id', $till->id)->where('opened_by', $user->id)->where('status', 'open')
        ->value('id');

    if ($existing !== null) {
        return (int) $existing;
    }

    return (int) DB::table('pos_shifts')->insertGetId([
        'business_id' => $till->business_id,
        'money_account_id' => $till->id,
        'opened_by' => $user->id,
        'opened_at' => now(),
        'opening_float' => '0.00',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function moneyHead(string $slug): Account
{
    $account = Account::bySlug($slug);

    MoneyAccount::query()->firstOrCreate(
        ['account_id' => $account->id],
        ['name' => $account->name, 'type' => match ($slug) {
            'cash_in_hand' => 'cash',
            'mobile_financial_services' => 'mfs',
            default => 'bank',
        }],
    );

    return $account;
}
