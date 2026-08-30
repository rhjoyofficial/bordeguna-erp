<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The assistant's vocabulary.
 *
 * ⚠⚠ THE TOOL LIST IS THE SECURITY MODEL, not a convenience. The assistant
 * cannot call what is not defined, so "reads but never writes" is the absence of
 * any writing tool rather than a rule the model is asked to obey. These tests
 * assert that absence, and that the list is built per person.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Tools Co', 'slug' => 'tools-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.view', 'sales.view', 'notes.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Storeman', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('inventory.view');
    Role::firstOrCreate(['name' => 'Nobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->storeman = User::factory()->create();
    $this->storeman->businesses()->attach($this->business->id);
    $this->storeman->assignRole('Storeman');

    $this->nobody = User::factory()->create();
    $this->nobody->businesses()->attach($this->business->id);
    $this->nobody->assignRole('Nobody');

    $this->tools = app(AssistantTools::class);
});

it('offers no tool that writes anything except the asker own notes and to-dos', function () {
    $names = array_column($this->tools->definitions($this->storeman), 'name');

    // ⚠⚠ The whole design in one assertion. If a tool that updates a product or
    // posts a journal ever appears here, this fails.
    sort($names);

    expect($names)->toBe([
        'complete_todo', 'create_note', 'create_todo', 'list_notes', 'list_todos', 'read_data',
    ]);
});

it('does not tell somebody about data they cannot read', function () {
    $storeman = collect($this->tools->definitions($this->storeman))->firstWhere('name', 'read_data');

    expect($storeman['description'])->toContain('products')
        ->and($storeman['description'])->not->toContain('sales_invoices');

    // Somebody with no read permissions is never offered the tool at all.
    $names = array_column($this->tools->definitions($this->nobody), 'name');

    expect($names)->not->toContain('read_data');
});

it('uses the camelCase schema key the PHP SDK expects', function () {
    // ⚠ `inputSchema`, not `input_schema`. The SDK maps it to the wire format;
    // the snake_case form is accepted silently and sends NO schema at all,
    // which shows up as the model inventing arguments.
    foreach ($this->tools->definitions($this->storeman) as $tool) {
        expect($tool)->toHaveKey('inputSchema')
            ->and($tool)->not->toHaveKey('input_schema');
    }
});

it('returns a refusal as an answer rather than throwing', function () {
    // ⚠⚠ A refusal must reach the model as ordinary tool output so it can say
    // "you cannot see that" in words. Throwing would abort the turn and show a
    // broken panel instead.
    $result = $this->tools->run($this->storeman, 'read_data', ['table' => 'payslips']);

    expect($result)->toContain('refused')
        ->and(json_decode($result, true))->toHaveKey('refused');
});

it('refuses a tool name it does not have', function () {
    $result = $this->tools->run($this->storeman, 'delete_everything', []);

    expect(json_decode($result, true)['refused'])->toContain('no tool called');
});

it('suggests only questions this person could actually get an answer to', function () {
    $storeman = $this->tools->suggestions($this->storeman);
    $nobody = $this->tools->suggestions($this->nobody);

    expect(implode(' ', $storeman))->toContain('stock')
        ->and(implode(' ', $storeman))->not->toContain('invoices');

    // ⚠ Everybody keeps their own list, so nobody is left with an empty panel.
    expect(implode(' ', $nobody))->toContain('to-do list');
});
