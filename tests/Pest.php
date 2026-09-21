<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
