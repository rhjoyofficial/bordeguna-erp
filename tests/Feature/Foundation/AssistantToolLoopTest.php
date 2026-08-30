<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\PersonalTodo;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tool loop, end to end, without calling a provider.
 *
 * ⚠⚠ THE CLOSURE THE DRIVER RECEIVES CLOSES OVER THE ASKING USER. That is the
 * join between "who is asking" and "what may be read", and it is the one place
 * a mistake would hand one person another's data while every unit test still
 * passed. So these drive the same closure ErpAssistant builds.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Loop Co', 'slug' => 'loop-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // ⚠ Each AI capability needs its own grant since 30 Aug 2026.
    foreach (['inventory.view', 'notes.view', 'foundation.ai.read', 'foundation.ai.notes', 'foundation.ai.todos'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Storeman', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.view', 'notes.view', 'foundation.ai.read', 'foundation.ai.notes', 'foundation.ai.todos']);

    $this->me = User::factory()->create();
    $this->me->businesses()->attach($this->business->id);
    $this->me->assignRole('Storeman');

    $this->colleague = User::factory()->create();
    $this->colleague->businesses()->attach($this->business->id);
    $this->colleague->assignRole('Storeman');

    $this->tools = app(AssistantTools::class);

    // Exactly the closure ErpAssistant hands the driver.
    $this->runTool = fn (string $tool, array $input): string => $this->tools->run($this->me, $tool, $input);
});

it('creates a to-do through the tool and it belongs to the asker', function () {
    $out = ($this->runTool)('create_todo', ['title' => 'Count the mustard oil', 'due_on' => '2026-08-31']);

    $created = json_decode($out, true);
    $todo = PersonalTodo::query()->withoutGlobalScopes()->find($created['id']);

    expect($todo->user_id)->toBe($this->me->id)
        ->and($todo->title)->toBe('Count the mustard oil');
});

it('cannot complete a colleague to-do through the tool either', function () {
    // ⚠⚠ The same attempt as the unit test, but through the exact path the
    // model would take. A gap between the two would be invisible otherwise.
    $theirs = app(AssistantTools::class)->run($this->colleague, 'create_todo', ['title' => 'Theirs']);
    $theirId = json_decode($theirs, true)['id'];

    $out = json_decode(($this->runTool)('complete_todo', ['id' => $theirId]), true);

    expect($out)->toHaveKey('refused');
    expect(PersonalTodo::query()->withoutGlobalScopes()->find($theirId)->completed_at)->toBeNull();
});

it('reads data it is allowed and refuses data it is not, through the same closure', function () {
    $allowed = json_decode(($this->runTool)('read_data', ['table' => 'products', 'limit' => 5]), true);
    expect($allowed)->toHaveKey('rows');

    $refused = json_decode(($this->runTool)('read_data', ['table' => 'sales_invoices']), true);
    expect($refused)->toHaveKey('refused');

    $never = json_decode(($this->runTool)('read_data', ['table' => 'payslips']), true);
    expect($never)->toHaveKey('refused');
});

it('describes a tool without naming the table or the arguments', function () {
    // ⚠ The progress line shown while a tool runs must not echo the request.
    // "Looking that up" is honest; `read_data {"table":"sales_invoices"}` would
    // put the schema on screen for somebody who may not know it exists.
    $driver = new ReflectionClass(\App\Modules\Foundation\Support\Ai\AnthropicDriver::class);
    $announce = $driver->getMethod('announce');
    $announce->setAccessible(true);
    $instance = app(\App\Modules\Foundation\Support\Ai\AnthropicDriver::class);

    foreach (['read_data', 'create_todo', 'list_notes', 'something_else'] as $tool) {
        $line = $announce->invoke($instance, $tool);

        expect($line)->not->toContain('{')
            ->and($line)->not->toContain('table')
            ->and(trim($line))->not->toBe('');
    }
});

it('caps how many times one turn may loop', function () {
    $driver = new ReflectionClass(\App\Modules\Foundation\Support\Ai\AnthropicDriver::class);

    // ⚠⚠ Without a ceiling a model that keeps asking for one more lookup bills
    // indefinitely and the panel never answers.
    expect($driver->getConstant('MAX_TOOL_ROUNDS'))->toBeInt()->toBeLessThanOrEqual(10);
});
