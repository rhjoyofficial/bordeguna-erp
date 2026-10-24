<?php

use App\Models\User;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every AI capability is off until somebody grants it (Joy, 30 August 2026).
 *
 * ⚠⚠ THE DEFAULT IS THAT THE ASSISTANT CAN DO NOTHING. `foundation.ai.use`
 * opens the panel and nothing more. Reading data, notes and to-dos are three
 * further grants held by NO ROLE out of the box - not even Owner. An assistant
 * nobody has configured cannot read a row, write a note or add a to-do.
 *
 * ⚠⚠ AND AN AI PERMISSION ONLY EVER NARROWS. `ai.read` grants the ability to USE
 * the reading tool; it grants access to no table. The module permissions still
 * decide that, so the two are AND and never OR.
 */
beforeEach(function () {
    // Notes are hidden since 24 Oct 2026 (Joy); the note tools are tested switched on.
    config(['features.notes' => true]);

    $this->business = Business::create(['name' => 'Grant Co', 'slug' => 'grant-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.view', 'notes.view', 'foundation.ai.read', 'foundation.ai.notes', 'foundation.ai.todos'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $this->roleFor = function (string $name, array $permissions) {
        $role = Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($name);

        return $user;
    };

    $this->tools = app(AssistantTools::class);
});

it('gives an assistant with no grants absolutely nothing to do', function () {
    // ⚠⚠ Somebody holding every MODULE permission in the business. Without the
    // AI grants the assistant still offers them not one tool.
    $user = ($this->roleFor)('Module Only', ['inventory.view', 'notes.view']);

    expect($this->tools->definitions($user))->toBe([]);
});

it('refuses a tool the person was never offered, even if the model names it', function () {
    // ⚠⚠ THE TEST THAT MATTERS MOST HERE. A model has seen thousands of APIs and
    // will guess tool names. If run() trusted definitions() to have gated
    // things, the tool list would be a suggestion rather than a boundary.
    $user = ($this->roleFor)('Module Only 2', ['inventory.view', 'notes.view']);

    foreach (['read_data', 'create_todo', 'complete_todo', 'create_note', 'list_notes'] as $tool) {
        $out = json_decode($this->tools->run($user, $tool, ['table' => 'products', 'title' => 'x', 'id' => 1]), true);

        // ⚠ array_key_exists + toBeTrue, NOT toHaveKey with a message. Pest
        // reads a second argument to toHaveKey as the expected VALUE, so the
        // message gets compared against the refusal text and the assertion
        // fails on wording it invented. Third time this family of matcher has
        // bitten this codebase - toContain does the same.
        expect(array_key_exists('refused', $out))->toBeTrue("{$tool} was allowed without a grant");
    }
});

it('grants one capability without granting the others', function () {
    $todosOnly = ($this->roleFor)('Todos Only', ['inventory.view', 'foundation.ai.todos']);

    $names = array_column($this->tools->definitions($todosOnly), 'name');
    sort($names);

    expect($names)->toBe(['complete_todo', 'create_todo', 'list_todos']);

    // ⚠ And reading is still refused, despite holding inventory.view.
    expect(json_decode($this->tools->run($todosOnly, 'read_data', ['table' => 'products']), true))
        ->toHaveKey('refused');
});

it('narrows and never widens: the AI grant alone opens no data', function () {
    // ⚠⚠ Holding foundation.ai.read WITHOUT any module permission reads nothing.
    // The two are AND. If this ever passes with rows, the AI permission has
    // started granting data access on its own.
    $aiOnly = ($this->roleFor)('AI Only', ['foundation.ai.read']);

    expect($this->tools->definitions($aiOnly))->toBe([]);

    expect(json_decode($this->tools->run($aiOnly, 'read_data', ['table' => 'products']), true))
        ->toHaveKey('refused');
});

it('opens reading only when both grants are held', function () {
    $both = ($this->roleFor)('Both', ['inventory.view', 'foundation.ai.read']);

    $names = array_column($this->tools->definitions($both), 'name');

    expect($names)->toBe(['read_data']);

    $result = json_decode($this->tools->run($both, 'read_data', ['table' => 'products', 'limit' => 3]), true);

    expect($result)->toHaveKey('rows');
});

it('seeds the AI permissions without giving them to any role', function () {
    // ⚠⚠ They must EXIST so they can be granted on Settings > Roles, and be held
    // by nobody. Putting them on Owner "so it works out of the box" would undo
    // the entire decision.
    (new FoundationPermissionsSeeder)->run();

    foreach (['foundation.ai.read', 'foundation.ai.notes', 'foundation.ai.todos'] as $name) {
        $permission = Permission::where('name', $name)->first();

        expect($permission)->not->toBeNull("{$name} was not created");
        expect($permission->roles()->count() === 0)->toBeTrue("{$name} was granted to a role by the seeder");
    }
});

it('suggests nothing it cannot actually do', function () {
    $nothing = ($this->roleFor)('Nothing At All', []);

    expect($this->tools->suggestions($nothing))->toBe([]);
});
