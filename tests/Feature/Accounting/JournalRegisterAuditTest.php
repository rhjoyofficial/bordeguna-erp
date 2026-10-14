<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The journal register and journal page, from the 14 Oct 2026 audit.
 *
 * ACC-004: "Reverse this journal" posted on one click, and was offered on a
 * journal another screen made, which the server always refuses.
 * ACC-006: the register ran two extra queries per row.
 * ACC-011: the data-entry guide filters the register by type; it had no filter.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Register Co', 'slug' => 'register-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.journal.post']);
    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
    moneyHead('cash_at_bank');

    $this->post = function (string $type, string $date, string $amount) {
        $this->actingAs($this->founder)->post(route('accounting.journals.store'), [
            'journal_date' => $date, 'memo' => 'Entry '.$amount, 'journal_type' => $type,
            'lines' => [
                ['account_id' => Account::bySlug('cash_at_bank')->id, 'debit' => $amount, 'credit' => '', 'memo' => ''],
                ['account_id' => Account::bySlug($type === 'opening_balance' ? 'opening_balance_equity' : 'owners_capital')->id, 'debit' => '', 'credit' => $amount, 'memo' => ''],
            ],
        ])->assertSessionHasNoErrors();

        return Journal::latest('id')->first();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('tells the journal page whether another screen made the entry (ACC-004)', function () {
    $manual = ($this->post)('manual', '2026-08-03', '1000');
    $sourced = ($this->post)('manual', '2026-08-03', '2000');
    DB::table('journals')->where('id', $sourced->id)->update(['source_type' => 'voucher', 'source_id' => 1]);

    $this->actingAs($this->founder)->get(route('accounting.journals.show', $manual->id))
        ->assertInertia(fn ($page) => $page->where('journal.has_source', false));
    $this->actingAs($this->founder)->get(route('accounting.journals.show', $sourced->id))
        ->assertInertia(fn ($page) => $page->where('journal.has_source', true));

    // The button asks before it posts, and is not offered for a sourced journal.
    $page = file_get_contents(resource_path('js/pages/accounting/journals/show.tsx'));
    expect($page)->toContain('<ConfirmDialog')
        ->and($page)->toContain('!journal.has_source')
        ->and($page)->not->toContain('onClick={reverse}');
});

it('filters the register by type (ACC-011)', function () {
    ($this->post)('opening_balance', '2026-06-30', '5000');
    ($this->post)('manual', '2026-08-03', '1000');

    $this->actingAs($this->founder)->get('/accounting/journals?type=opening_balance')
        ->assertInertia(fn ($page) => $page
            ->where('type', 'opening_balance')
            ->has('journals.data', 1)
            ->where('journals.data.0.type', 'opening_balance'));

    $this->actingAs($this->founder)->get('/accounting/journals?type=nonsense')
        ->assertInertia(fn ($page) => $page->where('type', null)->has('journals.data', 2));
});

it('reads the register in a fixed number of queries, however many rows (ACC-006)', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->founder)->get('/accounting/journals')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    ($this->post)('manual', '2026-08-03', '1000');
    $count(); // the first request also warms per-request caches
    $few = $count();

    foreach (range(1, 5) as $i) {
        ($this->post)('manual', '2026-08-03', (string) (1000 + $i));
    }

    expect($count())->toBe($few);

    $this->actingAs($this->founder)->get('/accounting/journals')
        ->assertInertia(fn ($page) => $page->where('journals.data.0.total', '1,005.00')->where('journals.data.0.reversed', false));
});
