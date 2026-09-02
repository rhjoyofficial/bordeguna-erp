<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ErpAssistant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The how-to tool: the assistant can teach anybody how to DO things in this
 * ERP, and the answer is filtered by who is asking (Joy, 2 Sep 2026: "teach
 * the inbuilt AI how do anything in the erp ... ai will also answer based on
 * the role permissions").
 *
 * ⚠⚠ The same question has different true answers for different roles: "how do
 * I add a movement" is My profile > My leave for an employee and HRM > Leave &
 * movement for HR. These tests pin that split, because a guide that walks a
 * storeman through the HR screen is worse than no guide at all.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Guide Co', 'slug' => 'guide-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['foundation.ai.use', 'hrm.leave.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $this->makeUser = function (array $permissions): User {
        static $n = 0;
        $role = Role::firstOrCreate([
            'name' => 'GuideRole'.(++$n),
            'business_id' => $this->business->id,
            'guard_name' => 'web',
        ]);

        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }

        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->tools = app(AssistantTools::class);
});

it('offers the how_to tool to a panel user and to nobody without the ai grant', function () {
    $with = ($this->makeUser)(['foundation.ai.use']);
    $without = ($this->makeUser)([]);

    $names = fn (User $user) => array_column($this->tools->definitions($user), 'name');

    expect($names($with))->toContain('how_to')
        ->and($names($without))->not->toContain('how_to');
});

it('teaches an employee the self-service movement path, never the HR screen', function () {
    $employee = ($this->makeUser)(['foundation.ai.use']);

    $result = json_decode($this->tools->run($employee, 'how_to', ['query' => 'how do I add a movement for official work']), true);

    expect($result['found'])->toBeTrue();

    $paths = array_column($result['articles'], 'path');
    $asOne = implode(' | ', $paths);

    // The reachable answer is the self-service tab...
    expect($asOne)->toContain('My profile')
        // ...and the HR administration screen is NOT offered as steps...
        ->and($asOne)->not->toContain('HRM > Leave & movement')
        // ...it is named only as something that exists out of reach.
        ->and(implode(' ', $result['blocked'] ?? []))->toContain('ANY employee');
});

it('teaches HR the administration movement screen as well', function () {
    $hr = ($this->makeUser)(['foundation.ai.use', 'hrm.leave.manage']);

    $result = json_decode($this->tools->run($hr, 'how_to', ['query' => 'record a movement for an employee']), true);

    expect($result['found'])->toBeTrue()
        ->and(implode(' | ', array_column($result['articles'], 'path')))->toContain('HRM > Leave & movement');
});

it('keeps the payroll run out of reach of a role without the payroll permission', function () {
    $employee = ($this->makeUser)(['foundation.ai.use']);
    $payroll = ($this->makeUser)(['foundation.ai.use', 'hrm.payroll.manage']);

    $forEmployee = json_decode($this->tools->run($employee, 'how_to', ['query' => 'how to calculate the payroll run']), true);
    $forPayroll = json_decode($this->tools->run($payroll, 'how_to', ['query' => 'how to calculate the payroll run']), true);

    $employeePaths = implode(' | ', array_column($forEmployee['articles'] ?? [], 'path'));

    expect($employeePaths)->not->toContain('Payroll > Payroll runs')
        ->and(implode(' | ', array_column($forPayroll['articles'], 'path')))->toContain('Payroll > Payroll runs');
});

it('answers an unknown topic with the topics this user may actually ask about', function () {
    $employee = ($this->makeUser)(['foundation.ai.use']);

    $result = json_decode($this->tools->run($employee, 'how_to', ['query' => 'xyzzy plugh']), true);

    expect($result['found'])->toBeFalse()
        ->and($result['topics'])->toContain('Your own profile and self-service')
        ->and($result['topics'])->not->toContain('Payroll');
});

it('refuses the how_to tool without the ai use grant, like every other tool', function () {
    $without = ($this->makeUser)([]);

    $result = json_decode($this->tools->run($without, 'how_to', ['query' => 'movement']), true);

    expect($result)->toHaveKey('refused');
});

it('tells the model in the static prompt to answer how-do-I questions from the tool', function () {
    $user = ($this->makeUser)(['foundation.ai.use']);

    $blocks = app(ErpAssistant::class)->systemBlocks($user);

    expect($blocks[0]['text'])->toContain('how_to');
});

it('carries the Hudhud naming story when the business has named her হুদহুদ', function () {
    $this->business->update(['ai_assistant_name' => 'হুদহুদ']);

    $user = ($this->makeUser)(['foundation.ai.use']);
    $blocks = app(ErpAssistant::class)->systemBlocks($user);

    // The per-user block: the name, the surah, and the rule the boss singled
    // out - when she does not know, she says so instead of guessing.
    expect($blocks[1]['text'])->toContain('হুদহুদ')
        ->and($blocks[1]['text'])->toContain('আন-নামল');
});

it('does not tell the naming story for a business that kept another name', function () {
    $this->business->update(['ai_assistant_name' => 'Guna']);

    $user = ($this->makeUser)(['foundation.ai.use']);
    $blocks = app(ErpAssistant::class)->systemBlocks($user);

    expect($blocks[1]['text'])->not->toContain('আন-নামল');
});
