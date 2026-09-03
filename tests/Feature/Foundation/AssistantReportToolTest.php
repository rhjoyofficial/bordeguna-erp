<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantRefusal;
use App\Modules\Foundation\Support\Ai\ReportTool;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 7, Slice 3: হুদহুদ runs reports.
 *
 * The same 23 definitions, the same per-report permission, capped rows.
 * The engine answers; the assistant narrates; nobody reaches a number
 * their own Reports screen would have refused them.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ask Co', 'slug' => 'ask-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->bystander = User::factory()->create();
    $this->bystander->businesses()->attach($this->business->id);
});

it('lists only the reports the person may run', function () {
    $tool = app(ReportTool::class);

    $ownerKeys = array_column($tool->available($this->owner), 'key');
    expect($ownerKeys)->toContain('sales_by_brand');

    expect($tool->available($this->bystander))->toBe([]);
});

it('runs a report through its own permission, and refuses without it', function () {
    $tool = app(ReportTool::class);

    $result = $tool->run($this->owner, ['report' => 'sales_by_brand']);
    expect($result['report'])->toBe('Sales by brand')
        ->and($result['columns'])->toContain('Invoiced value')
        ->and($result['rows'])->toBe([]);

    expect(fn () => $tool->run($this->bystander, ['report' => 'sales_by_brand']))
        ->toThrow(AssistantRefusal::class);

    expect(fn () => $tool->run($this->owner, ['report' => 'no_such_report']))
        ->toThrow(AssistantRefusal::class);
});
