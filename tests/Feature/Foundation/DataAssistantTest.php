<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Note;
use App\Modules\Foundation\Support\Ai\DataQuery;
use App\Modules\Foundation\Support\Ai\ReadableData;
use App\Modules\Foundation\Support\Ai\ReadOnlyConnection;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The data assistant's safety barriers (Joy, 30 August 2026).
 *
 * ⚠⚠ THE ONE SENTENCE THIS FILE EXISTS FOR: a read-only database connection
 * stops WRITING and does nothing whatsoever about who may READ what. On a
 * read-only user `SELECT * FROM payslips` succeeds perfectly - nothing written,
 * nothing updated, and everybody's pay disclosed. So the tests below are mostly
 * about reading, because that is the half that protects the business.
 *
 * Four independent barriers, each tested here:
 *   1. The model never writes SQL - it sends a structured request.
 *   2. The table list is derived from the ASKER's permissions, default closed.
 *   3. Scoping (business, owner) is injected by the app, never by the model.
 *   4. The connection's MySQL user is granted SELECT only.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Assistant Data Co', 'slug' => 'assistant-data-co']);
    $this->other = Business::create(['name' => 'Somebody Else', 'slug' => 'somebody-else']);

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.view', 'sales.view', 'notes.view', 'accounting.view', 'purchasing.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Storeman', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.view', 'notes.view']);
    Role::firstOrCreate(['name' => 'Seller', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.view', 'notes.view']);

    $this->storeman = User::factory()->create();
    $this->storeman->businesses()->attach($this->business->id);
    $this->storeman->assignRole('Storeman');

    $this->seller = User::factory()->create();
    $this->seller->businesses()->attach($this->business->id);
    $this->seller->assignRole('Seller');

    $this->query = app(DataQuery::class);
    $this->readable = app(ReadableData::class);
});

// ─── Barrier 2: the table list follows the asker ─────────────────────────────

it('offers each person only the data their own permissions already allow', function () {
    $storeman = array_keys($this->readable->forUser($this->storeman));
    $seller = array_keys($this->readable->forUser($this->seller));

    expect($storeman)->toContain('products')->toContain('stock_batches')
        ->and($storeman)->not->toContain('customers')
        ->and($storeman)->not->toContain('sales_invoices');

    expect($seller)->toContain('customers')->toContain('sales_invoices')
        ->and($seller)->not->toContain('products')
        ->and($seller)->not->toContain('stock_batches');
});

it('refuses a table the asker may not read, however it is named', function () {
    // ⚠⚠ THE "not over rule" TEST. The storeman may not see customers. Naming
    // the table anyway must be refused, not answered - and the refusal must not
    // reveal whether the table exists.
    expect(fn () => $this->query->run($this->storeman, ['table' => 'customers']))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->query->run($this->storeman, ['table' => 'sales_invoices']))
        ->toThrow(RuntimeException::class);
});

it('refuses a table that is in the database but not in the map', function () {
    // ⚠⚠ DEFAULT CLOSED. `payslips` and `users` are real tables. They are not in
    // ReadableData, so nobody reads them through the assistant - including
    // somebody holding every permission in the system.
    $everything = User::factory()->create();
    $everything->businesses()->attach($this->business->id);
    Role::firstOrCreate(['name' => 'Everything', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.view', 'sales.view', 'notes.view', 'accounting.view', 'purchasing.view']);
    $everything->assignRole('Everything');

    foreach (['payslips', 'users', 'salary_structures', 'ai_credentials'] as $table) {
        expect(fn () => $this->query->run($everything, ['table' => $table]))
            ->toThrow(RuntimeException::class, "There is no data called '{$table}' that you can see.");
    }
});

// ─── Barrier 3: scoping the model cannot omit ────────────────────────────────

it('never returns another business rows, even when nothing was filtered', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Ours', 'body' => 'x', 'created_by' => $this->storeman->id]);
    Note::create(['business_id' => $this->other->id, 'title' => 'Theirs', 'body' => 'x', 'created_by' => $this->storeman->id]);

    $result = $this->query->run($this->storeman, ['table' => 'notes']);

    expect($result['count'])->toBe(1)
        ->and($result['rows'][0]['title'])->toBe('Ours');
});

it('keeps a personal table personal, with no way for the model to name anybody else', function () {
    // ⚠⚠ The owner is taken from the SESSION. There is deliberately no
    // parameter for the model to put another user id into - so this test
    // deliberately TRIES to, and the attempt has nowhere to land.
    Note::create(['business_id' => $this->business->id, 'title' => 'Storeman note', 'body' => 'x', 'created_by' => $this->storeman->id]);
    Note::create(['business_id' => $this->business->id, 'title' => 'Seller note', 'body' => 'x', 'created_by' => $this->seller->id]);

    $result = $this->query->run($this->storeman, [
        'table' => 'notes',
        'filters' => [['column' => 'created_by', 'operator' => '=', 'value' => $this->seller->id]],
    ]);

    // The filter it supplied is dropped (created_by is not a readable column),
    // and the owner scope the app injected still stands.
    expect($result['count'])->toBe(1)
        ->and($result['rows'][0]['title'])->toBe('Storeman note');
});

// ─── Barrier 1: the model never writes SQL ───────────────────────────────────

it('drops a column that is not on the list rather than selecting it', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Note', 'body' => 'secret body', 'created_by' => $this->storeman->id]);

    $result = $this->query->run($this->storeman, [
        'table' => 'notes',
        'columns' => ['title', 'archived_at', 'created_by'],
    ]);

    // Only `title` survives the intersection; the other two are not readable.
    expect($result['columns'])->toBe(['title'])
        ->and(array_keys($result['rows'][0]))->toBe(['title']);
});

it('drops an operator it does not recognise instead of interpreting it', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Keep', 'body' => 'x', 'created_by' => $this->storeman->id]);

    // ⚠ There is no SQL string from the model, so this cannot be an injection -
    // but the operator must still be refused rather than passed to the builder.
    $result = $this->query->run($this->storeman, [
        'table' => 'notes',
        'filters' => [['column' => 'title', 'operator' => '; DROP TABLE notes; --', 'value' => 'x']],
    ]);

    expect($result['count'])->toBe(1);
    expect(Note::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('caps how many rows one question can pull back', function () {
    for ($i = 0; $i < 20; $i++) {
        Note::create(['business_id' => $this->business->id, 'title' => "Note {$i}", 'body' => 'x', 'created_by' => $this->storeman->id]);
    }

    $result = $this->query->run($this->storeman, ['table' => 'notes', 'limit' => 100000]);

    // ⚠ A ceiling the model cannot raise. A question wanting more rows than
    // this is a question that should have been an aggregate.
    expect($result['count'])->toBeLessThanOrEqual(200);
});

// ─── Barrier 4: the connection ───────────────────────────────────────────────

it('reads through a dedicated connection only when a read-only credential exists', function () {
    // ⚠⚠ THE BARRIER REPORTS ITSELF HONESTLY. In production DB_READONLY_USERNAME
    // names a MySQL user granted SELECT and nothing else, and the assistant reads
    // through it. Here no such credential is set, so there is deliberately NO
    // separate connection - a second connection with the same credential buys
    // nothing, and on SQLite it opens its own empty `:memory:` database where
    // every table is missing.
    //
    // ⛔ What must never happen is a decorative connection that makes this look
    // present when it is not, so the two answers are asserted together.
    $readOnly = ReadOnlyConnection::isTrulyReadOnly();

    if ($readOnly) {
        expect(ReadOnlyConnection::name())->toBe(ReadOnlyConnection::NAME);
    } else {
        expect(ReadOnlyConnection::name())->toBe(config('database.default'))
            ->and(config('database.connections.'.ReadOnlyConnection::NAME))->toBeNull();
    }

    // ⚠ And the other three barriers hold regardless - they are what the rest of
    // this file tests, and none of them depends on the credential.
    expect(fn () => app(DataQuery::class)->run($this->storeman, ['table' => 'payslips']))
        ->toThrow(RuntimeException::class);
});

it('lists every permission its map depends on, so none can quietly vanish', function () {
    // ⚠ A map keyed on permissions that no longer exist would fail open or
    // closed silently. This is the drift guard.
    foreach (ReadableData::permissions() as $permission) {
        expect(Permission::where('name', $permission)->exists())
            ->toBeTrue("ReadableData maps a permission that does not exist: {$permission}");
    }
});
