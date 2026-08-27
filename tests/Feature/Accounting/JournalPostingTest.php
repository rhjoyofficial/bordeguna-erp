<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\ImmutableJournalException;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2.2 of Accounting: the journal, its lines, and the posting engine.
 *
 * ⚠⚠ PostJournal is the ONLY way into the General Ledger, and every guard below
 * throws rather than returning false - a caller that forgets to check a boolean
 * writes a broken entry into the books.
 *
 * See ACCOUNTING-LEDGER-ARCHITECTURE.md §3 and §4.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create([
        'name' => 'Journal Co',
        'slug' => 'journal-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->cash = Account::bySlug('cash_in_hand');
    $this->bank = Account::bySlug('cash_at_bank');
    $this->capital = Account::bySlug('owners_capital');
    $this->rent = Account::where('code', '6200')->first();

    $this->post = fn (JournalDraft $draft) => app(PostJournal::class)->handle($draft, $this->user);

    $this->draft = fn (array $lines, ?string $date = null, ?string $memo = null) => new JournalDraft(
        date: Carbon::parse($date ?? '2026-08-03'),
        lines: $lines,
        memo: $memo,
    );
});

// ─── The thing this whole phase exists to make possible ─────────────────────

it('posts a capital introduction and balances', function () {
    // ⚠⚠ THE MILESTONE, in miniature: Joy puts 500,000 taka of his own money
    // into the business and the ledger records both halves of it.
    $journal = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->bank->id, Money::ofTaka('500000')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500000')),
    ], memo: 'Capital introduced by the Founder'));

    expect($journal->lines)->toHaveCount(2)
        ->and($journal->balance()->isZero())->toBeTrue()
        ->and($journal->totalDebits()->format())->toBe('500,000.00')
        ->and($journal->memo)->toBe('Capital introduced by the Founder');

    // Stored as whole paisa, signed. Positive is a debit.
    expect($journal->lines[0]->amount_minor)->toBe(50000000)
        ->and($journal->lines[1]->amount_minor)->toBe(-50000000);
});

it('numbers journals per business and per fiscal year', function () {
    // C-LGR-2, taken as a default: JV-2026-27-0001. Bor de Guna's year runs
    // 1 July to 30 June, so August 2026 is fiscal year 2026-27 and May 2026 is
    // the year before it.
    $first = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    $second = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('200')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('200')),
    ]));

    $earlier = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('300')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('300')),
    ], date: '2026-05-20'));

    expect($first->journal_number)->toBe('JV-2026-27-0001')
        ->and($second->journal_number)->toBe('JV-2026-27-0002')
        // ⚠ A different fiscal year keeps its own sequence, and 20 May 2026
        // belongs to 2025-26 rather than to the year that started in July.
        ->and($earlier->journal_number)->toBe('JV-2025-26-0001');
});

// ─── What it refuses ────────────────────────────────────────────────────────

it('refuses a journal that does not balance', function () {
    // ⚠⚠ The invariant the whole module rests on, checked at WRITE time. There
    // is no unbalanced draft state in the ledger; drafts live in the document
    // that produces them.
    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->bank->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('499.99')),
    ])))->toThrow(JournalException::class, 'does not balance');

    expect(Journal::count())->toBe(0)
        ->and(JournalLine::count())->toBe(0);
});

it('refuses a journal with fewer than two lines', function () {
    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->bank->id, Money::ofTaka('500')),
    ])))->toThrow(JournalException::class, 'at least two lines');
});

it('refuses a line that moves nothing', function () {
    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->bank->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
        JournalLineDraft::debit($this->rent->id, Money::zero()),
    ])))->toThrow(JournalException::class, 'moves nothing');
});

it('refuses to post to a heading', function () {
    // ⚠ Posting to a group would double-count it against its own children, which
    // is the quiet way a set of books stops adding up.
    $group = Account::where('code', '6000')->first();
    expect($group->is_postable)->toBeFalse();

    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($group->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ])))->toThrow(JournalException::class, 'is a heading');
});

it('refuses to post to a deactivated account', function () {
    $this->rent->update(['is_active' => false]);

    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ])))->toThrow(JournalException::class, 'deactivated');
});

it('refuses a journal dated in the future', function () {
    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ], date: '2026-09-01')))->toThrow(JournalException::class, 'in the future');
});

it('refuses a journal dated into a closed month', function () {
    // ⚠⚠ The SAME lock Inventory and payroll disbursement already use, which is
    // the whole reason Phase 1.2 generalised it instead of letting Accounting
    // introduce a second one that could disagree.
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user);

    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ], date: '2026-07-31')))->toThrow(PeriodClosedException::class);

    // Inclusive boundary: "closed through the 31st" closes the 31st and leaves
    // the 1st open. There is a test for that exact date in Inventory too.
    $august = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ], date: '2026-08-01'));

    expect($august->exists)->toBeTrue();
});

it('refuses a money account belonging to another business', function () {
    $other = Business::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
    $foreign = MoneyAccount::create([
        'business_id' => $other->id,
        'name' => 'Their bank',
        'type' => MoneyAccountType::Bank,
    ]);

    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->bank->id, Money::ofTaka('500'), moneyAccount: $foreign),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ])))->toThrow(JournalException::class, 'different business');
});

it('refuses to post with no business selected', function () {
    app(BusinessContext::class)->forget();

    expect(fn () => ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('500')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ])))->toThrow(JournalException::class, 'must belong to a business');
});

it('leaves nothing behind when it refuses', function () {
    // ⚠ The number is handed out inside the transaction, so a refusal must not
    // burn one - otherwise a business's voucher numbers develop gaps that an
    // auditor will ask about and nobody will be able to explain.
    ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    try {
        ($this->post)(($this->draft)([
            JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
            JournalLineDraft::credit($this->capital->id, Money::ofTaka('99')),
        ]));
    } catch (JournalException) {
        // expected
    }

    $next = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    expect($next->journal_number)->toBe('JV-2026-27-0002');
});

// ─── Immutability, and the only correction there is ─────────────────────────

it('refuses to let a posted journal be edited or deleted', function () {
    $journal = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    expect(fn () => $journal->update(['memo' => 'changed']))
        ->toThrow(ImmutableJournalException::class);

    expect(fn () => $journal->delete())
        ->toThrow(ImmutableJournalException::class);

    expect(fn () => $journal->lines->first()->update(['amount_minor' => 1]))
        ->toThrow(ImmutableJournalException::class);

    expect(fn () => $journal->lines->first()->delete())
        ->toThrow(ImmutableJournalException::class);
});

it('corrects a mistake by posting its mirror image', function () {
    $original = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('12000')),
        JournalLineDraft::credit($this->bank->id, Money::ofTaka('12000')),
    ], memo: 'August rent'));

    $reversal = app(ReverseJournal::class)->handle($original, $this->user);

    expect($reversal->reverses_journal_id)->toBe($original->id)
        ->and($reversal->isReversal())->toBeTrue()
        ->and($original->fresh()->hasBeenReversed())->toBeTrue()
        ->and($reversal->memo)->toContain('Reversal of JV-2026-27-0001')
        ->and($reversal->memo)->toContain('August rent');

    // Every line is the opposite of the original's, and the pair nets to zero -
    // which is what "the mistake is still visible and no longer counts" means.
    expect($reversal->lines[0]->amount_minor)->toBe(-1200000)
        ->and($reversal->lines[1]->amount_minor)->toBe(1200000);

    $net = JournalLine::where('account_id', $this->rent->id)->sum('amount_minor');
    expect((int) $net)->toBe(0);

    // ⚠ Both entries are still there. The correction did not erase the error;
    // that visibility IS the audit trail rather than a by-product of it.
    expect(Journal::count())->toBe(2);
});

it('dates a reversal today rather than back on the original', function () {
    $original = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('12000')),
        JournalLineDraft::credit($this->bank->id, Money::ofTaka('12000')),
    ], date: '2026-07-05'));

    $reversal = app(ReverseJournal::class)->handle($original, $this->user);

    // ⚠ Back-dating a reversal quietly changes a month that has already been
    // reported. Somebody who genuinely wants that passes the date explicitly,
    // and the period lock still has the final say.
    expect($reversal->journal_date->toDateString())->toBe('2026-08-03');

    $explicit = app(ReverseJournal::class)->handle(
        ($this->post)(($this->draft)([
            JournalLineDraft::debit($this->rent->id, Money::ofTaka('5000')),
            JournalLineDraft::credit($this->bank->id, Money::ofTaka('5000')),
        ], date: '2026-07-06')),
        $this->user,
        Carbon::parse('2026-07-31'),
    );

    expect($explicit->journal_date->toDateString())->toBe('2026-07-31');
});

it('reverses a journal once and only once', function () {
    $original = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->bank->id, Money::ofTaka('100')),
    ]));

    $reversal = app(ReverseJournal::class)->handle($original, $this->user);

    // Two reversals of one entry cancel each other and leave the original live
    // again, which is a state nobody reading the ledger would expect to find.
    expect(fn () => app(ReverseJournal::class)->handle($original->fresh(), $this->user))
        ->toThrow(JournalException::class, 'already been reversed');

    // And a reversal is a leaf: chaining them turns "is this still live?" into a
    // walk rather than a fact.
    expect(fn () => app(ReverseJournal::class)->handle($reversal, $this->user))
        ->toThrow(JournalException::class, 'itself a reversal');
});

it('refuses to reverse into a closed month', function () {
    $original = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->bank->id, Money::ofTaka('100')),
    ], date: '2026-07-10'));

    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user);

    // ⚠ A reversal is an ordinary journal and faces every guard the original
    // faced. It is not a privileged write.
    expect(fn () => app(ReverseJournal::class)->handle($original, $this->user, Carbon::parse('2026-07-15')))
        ->toThrow(PeriodClosedException::class);
});

// ─── Tenancy ────────────────────────────────────────────────────────────────

it('keeps one business out of another business books', function () {
    ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    $other = Business::create(['name' => 'Other Books', 'slug' => 'other-books', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($other->id);

    expect(Journal::count())->toBe(0)
        ->and(JournalLine::count())->toBe(0);

    // ⚠ The CHART, by contrast, is still fully visible - it is one shared chart
    // with separate balances, and this is what that looks like in practice.
    expect(Account::count())->toBeGreaterThan(40);

    // The second business starts its own numbering, because a voucher number
    // belongs to a set of books rather than to the installation.
    $theirs = app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-03'),
        lines: [
            JournalLineDraft::debit($this->cash->id, Money::ofTaka('50')),
            JournalLineDraft::credit($this->capital->id, Money::ofTaka('50')),
        ],
    ), $this->user);

    expect($theirs->journal_number)->toBe('JV-2026-27-0001');
});

it('stamps every line with the business its journal belongs to', function () {
    $journal = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('100')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
    ]));

    // ⚠ business_id on the line is a denormalised copy of the journal's, kept so
    // the trial balance does not join every line back to find out whose it is.
    // It is genuinely a second copy, so slice 2.4's integrity command checks the
    // two agree - exactly as stock_movements carries its own warehouse_id and
    // level 2 of inventory:verify-ledger exists to catch it drifting.
    foreach ($journal->lines as $line) {
        expect($line->business_id)->toBe($journal->business_id);
    }
});

// ─── The audit chain ────────────────────────────────────────────────────────

it('remembers who posted it and what caused it', function () {
    $supplierBillStandIn = $this->business;

    $journal = app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-03'),
        lines: [
            JournalLineDraft::debit($this->rent->id, Money::ofTaka('100'), memo: 'July rent', party: $this->user),
            JournalLineDraft::credit($this->bank->id, Money::ofTaka('100')),
        ],
        source: $supplierBillStandIn,
    ), $this->user);

    // ⚠ From any figure in a statement a human must be able to reach the event
    // that produced it. That chain is the entire value of building this rather
    // than buying it.
    expect($journal->source->is($supplierBillStandIn))->toBeTrue()
        ->and($journal->postedBy->is($this->user))->toBeTrue()
        ->and($journal->posted_at)->not->toBeNull()
        ->and($journal->lines[0]->party->is($this->user))->toBeTrue()
        ->and($journal->lines[0]->memo)->toBe('July rent');
});

it('numbers its lines in the order they were given', function () {
    $journal = ($this->post)(($this->draft)([
        JournalLineDraft::debit($this->rent->id, Money::ofTaka('60')),
        JournalLineDraft::debit($this->cash->id, Money::ofTaka('40')),
        JournalLineDraft::credit($this->bank->id, Money::ofTaka('100')),
    ]));

    expect($journal->lines->pluck('line_no')->all())->toBe([1, 2, 3])
        ->and($journal->lines->pluck('account_id')->all())
        ->toBe([$this->rent->id, $this->cash->id, $this->bank->id]);
});
