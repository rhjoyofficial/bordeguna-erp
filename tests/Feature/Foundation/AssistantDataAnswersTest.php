<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\Ai\DataQuery;
use App\Modules\Foundation\Support\Ai\ReadableData;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the assistant ANSWERS with, as opposed to what it is allowed to reach.
 *
 * ⚠⚠ BOTH TESTS HERE COME FROM THE SAME REAL RUN. On 30 August 2026 the panel
 * was used in anger for the first time and got two data questions wrong in two
 * different ways: it said there were 50 products when there are 153, and it
 * could not read the chart of accounts at all - then told an Owner his role was
 * the reason. Neither was caught by the existing tests, which only ever query
 * business-scoped tables and never enough rows to fill a page.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Answers Co', 'slug' => 'answers-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.view', 'accounting.view', 'foundation.ai.read'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.view', 'accounting.view', 'foundation.ai.read']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Reader');

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc',
    ]);

    // Products in bulk, cheaply: these tests care only about how many there are.
    $this->makeProducts = function (int $count, bool $active = true) {
        foreach (range(1, $count) as $n) {
            Product::create([
                'business_id' => $this->business->id,
                'unit_of_measure_id' => $this->unit->id,
                'sku' => sprintf('SKU-%s-%03d', $active ? 'A' : 'I', $n),
                'name' => 'Product '.($active ? 'A' : 'I').$n,
                'type' => ProductType::FinishedGood,
                'is_active' => $active,
            ]);
        }
    };

    $this->tools = app(AssistantTools::class);
});

/**
 * ⛔ WITHOUT THE FIX THIS THROWS, IT DOES NOT MERELY RETURN NOTHING.
 * DataQuery added `where accounts.business_id = ?` to every table alike, and
 * the chart of accounts has no such column - one shared chart serves all four
 * businesses (C-GL-1). Every question about an account died on
 * "Unknown column 'business_id'", which AssistantTools then handed to the model
 * as the reason it could not answer.
 */
it('reads the chart of accounts, which is shared rather than business scoped', function () {
    Account::firstOrCreate(['code' => '6225'], ['name' => 'Software and Hosting', 'type' => 'expense', 'is_postable' => true]);
    Account::create(['code' => '1010', 'name' => 'Cash in Hand', 'type' => 'asset', 'is_postable' => true]);

    $result = json_decode($this->tools->run($this->user, 'read_data', ['table' => 'accounts']), true);

    expect($result)->not->toHaveKey('refused')
        // ⚠ Not a literal 2: the chart grows (2145 arrived with expense
        // claims). What matters is that the count is the WHOLE shared chart.
        ->and($result['total'])->toBe(Account::count())
        ->and(collect($result['rows'])->pluck('code')->all())->toContain('6225');
});

/**
 * ⛔ WITHOUT THE FIX `total` DOES NOT EXIST, and the only count in the result is
 * the number of rows on the page. A model asked "how many products do we have"
 * counts what it was handed and answers with the page size - which is how a
 * catalogue of 153 was reported to the Owner as 50, in a full sentence, with no
 * hint that anything had been left out.
 */
it('reports how many rows exist, not how many fitted on the page', function () {
    // One more than the default page of 50, so the two numbers cannot coincide.
    ($this->makeProducts)(60);

    $result = json_decode($this->tools->run($this->user, 'read_data', ['table' => 'products']), true);

    expect($result['total'])->toBe(60)
        ->and($result['count'])->toBe(50)
        ->and($result['truncated'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(50);
});

/** A filtered count is still the count of what MATCHES, not of what came back. */
it('counts what matches the filter rather than what was returned', function () {
    ($this->makeProducts)(55, true);
    ($this->makeProducts)(3, false);

    $result = json_decode($this->tools->run($this->user, 'read_data', [
        'table' => 'products',
        'filters' => [['column' => 'is_active', 'operator' => '=', 'value' => false]],
    ]), true);

    expect($result['total'])->toBe(3)
        ->and($result['truncated'])->toBeFalse();
});

/**
 * ⚠⚠ THE AUDIT COLUMN THE MIGRATION PROMISED. `tools_used` existed from the day
 * the table was created and nothing ever wrote to it, so every row said NULL -
 * including the turns that read the whole product list.
 */
it('remembers which tools ran, by name', function () {
    Account::create(['code' => '1010', 'name' => 'Cash in Hand', 'type' => 'asset', 'is_postable' => true]);

    expect($this->tools->used())->toBe([]);

    $this->tools->run($this->user, 'read_data', ['table' => 'accounts']);
    $this->tools->run($this->user, 'read_data', ['table' => 'products']);

    expect($this->tools->used())->toBe(['read_data', 'read_data']);
});

/**
 * ⚠⚠ AN INTERNAL FAILURE MUST NOT BECOME A SENTENCE THE MODEL CAN REPEAT. The
 * catch-all used to return $e->getMessage() for any throwable, which meant a
 * database error travelled to the model complete with the SQL, the connection
 * host and the schema name.
 */
it('never hands a database error back as the reason', function () {
    // ⚠ A REAL QueryException, not a RuntimeException dressed up as one. The
    // message below is the shape Laravel actually produces, host and SQL and
    // all - and it is what the assistant used to repeat.
    app()->bind(DataQuery::class, fn () => new class(app(ReadableData::class), app(BusinessContext::class)) extends DataQuery
    {
        public function run(User $user, array $request): array
        {
            throw new QueryException(
                'mysql',
                'select * from `accounts` where `accounts`.`business_id` = ?',
                [4],
                new Exception("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'business_id' in 'where clause'"),
            );
        }
    });

    $result = json_decode(app(AssistantTools::class)->run($this->user, 'read_data', ['table' => 'accounts']), true);

    expect($result['refused'])->not->toContain('SQL')
        ->and($result['refused'])->not->toContain('mysql')
        ->and($result['refused'])->not->toContain('business_id')
        ->and($result['refused'])->not->toContain('SQLSTATE');
});
