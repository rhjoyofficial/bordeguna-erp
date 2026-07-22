<?php

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A minimal Approvable stand-in so the engine can be tested without dragging in
 * a whole Inventory/Purchasing document. It never needs its own table: the
 * Approvable trait only writes an approval_requests row keyed by this model's
 * class + id.
 */
class WorkflowFakeApprovable extends Model
{
    use Approvable;

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'Flow Co', 'slug' => 'flow-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['HR', 'Finance', 'Management', 'Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Permission::firstOrCreate(['name' => 'workflow.test.approve', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()->givePermissionTo('workflow.test.approve');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->requester = User::factory()->create();
    $this->requester->businesses()->attach($this->business->id);

    $this->fake = new WorkflowFakeApprovable();
    $this->fake->id = 1;
    $this->fake->business_id = $this->business->id;
});

/**
 * @param  array<int, array{name: string, roles?: array<string>, permissions?: array<string>}>  $stages
 */
function makeWorkflow(array $stages, string $key = 'workflow_fake_approvable'): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::create([
        'business_id' => test()->business->id,
        'key' => $key,
        'name' => 'Test workflow',
    ]);

    foreach ($stages as $i => $stage) {
        $workflow->stages()->create([
            'sequence' => $i + 1,
            'name' => $stage['name'],
            'allowed_roles' => $stage['roles'] ?? [],
            'allowed_permissions' => $stage['permissions'] ?? [],
        ]);
    }

    return $workflow;
}

function submitOn(?ApprovalWorkflow $workflow)
{
    // Attach a specific workflow directly (bypassing key resolution) so the
    // engine mechanics can be driven stage by stage.
    return test()->fake->approvalRequest()->create([
        'business_id' => test()->business->id,
        'workflow_id' => $workflow?->id,
        'current_stage' => 1,
        'status' => ApprovalStatus::Pending,
        'requested_by' => test()->requester->id,
    ]);
}

function roleUser(string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach(test()->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId(test()->business->id);
    $user->assignRole($role);

    return $user;
}

it('completes a single-stage workflow on the first approval (unchanged behavior)', function () {
    $request = submitOn(makeWorkflow([['name' => 'Approval', 'roles' => ['HR']]]));
    $hr = roleUser('HR');

    $completed = $request->approve($hr);

    expect($completed)->toBeTrue();
    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect($request->fresh()->reviewed_by)->toBe($hr->id);
    expect($request->decisions()->count())->toBe(1);
    expect($request->fresh()->remainingStages())->toBe(0);
    expect($request->fresh()->isComplete())->toBeTrue();
});

it('advances through a three-stage workflow and only completes at the last stage', function () {
    $request = submitOn(makeWorkflow([
        ['name' => 'HR Head', 'roles' => ['HR']],
        ['name' => 'Finance Head', 'roles' => ['Finance']],
        ['name' => 'Management', 'roles' => ['Management']],
    ]));

    expect($request->totalStages())->toBe(3);
    expect($request->currentStage()->name)->toBe('HR Head');
    expect($request->remainingStages())->toBe(3);

    expect($request->approve(roleUser('HR')))->toBeFalse();
    $request->refresh();
    expect($request->status)->toBe(ApprovalStatus::Pending);
    expect($request->current_stage)->toBe(2);
    expect($request->currentStage()->name)->toBe('Finance Head');
    expect($request->remainingStages())->toBe(2);

    expect($request->approve(roleUser('Finance')))->toBeFalse();
    expect($request->fresh()->current_stage)->toBe(3);

    expect($request->fresh()->approve(roleUser('Management')))->toBeTrue();
    $request->refresh();
    expect($request->status)->toBe(ApprovalStatus::Approved);
    expect($request->decisions()->count())->toBe(3);
    expect($request->decisions->pluck('stage_name')->all())->toBe(['HR Head', 'Finance Head', 'Management']);
});

it('records every stage decision separately with decider and outcome', function () {
    $request = submitOn(makeWorkflow([
        ['name' => 'HR Head', 'roles' => ['HR']],
        ['name' => 'Finance Head', 'roles' => ['Finance']],
    ]));
    $hr = roleUser('HR');

    $request->approve($hr);

    $decision = $request->decisions()->first();
    expect($decision->stage_sequence)->toBe(1);
    expect($decision->stage_name)->toBe('HR Head');
    expect($decision->decided_by)->toBe($hr->id);
    expect($decision->decision)->toBe(ApprovalStatus::Approved);
});

it('ends the whole request when any stage rejects', function () {
    $request = submitOn(makeWorkflow([
        ['name' => 'HR Head', 'roles' => ['HR']],
        ['name' => 'Finance Head', 'roles' => ['Finance']],
    ]));

    $request->reject(roleUser('HR'), 'Not this month');

    $request->refresh();
    expect($request->status)->toBe(ApprovalStatus::Rejected);
    expect($request->review_note)->toBe('Not this month');
    expect($request->decisions()->count())->toBe(1);
    expect($request->decisions()->first()->decision)->toBe(ApprovalStatus::Rejected);
    expect($request->remainingStages())->toBe(0);
});

it('authorizes each stage by its own roles or permissions', function () {
    $request = submitOn(makeWorkflow([
        ['name' => 'HR Head', 'roles' => ['HR']],
        ['name' => 'Finance gate', 'permissions' => ['workflow.test.approve']],
    ]));

    $hr = roleUser('HR');
    $financeByPermission = roleUser('Staff'); // Staff holds workflow.test.approve

    // Stage 1 (HR role): HR can act, the permission-holder cannot yet.
    expect($request->canBeActionedBy($hr))->toBeTrue();
    expect($request->canBeActionedBy($financeByPermission))->toBeFalse();

    $request->approve($hr);
    $request->refresh();

    // Stage 2 (permission): now the permission-holder can act, HR cannot.
    expect($request->canBeActionedBy($financeByPermission))->toBeTrue();
    expect($request->canBeActionedBy($hr))->toBeFalse();
});

it('lets a super admin act on any stage', function () {
    $request = submitOn(makeWorkflow([['name' => 'HR Head', 'roles' => ['HR']]]));

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdmin->businesses()->attach($this->business->id);

    expect($request->canBeActionedBy($superAdmin))->toBeTrue();
});

it('treats a request with no configured workflow as a single implicit stage', function () {
    $request = submitOn(null);

    expect($request->totalStages())->toBe(1);
    expect($request->currentStage())->toBeNull();
    expect($request->approve($this->requester))->toBeTrue();
    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect($request->decisions()->first()->stage_name)->toBe('Approval');
});

it('resolves the business workflow by key on submitForApproval', function () {
    $workflow = makeWorkflow([['name' => 'Approval', 'roles' => ['HR']]], key: 'workflow_fake_approvable');

    $request = $this->fake->submitForApproval($this->requester);

    expect($request->workflow_id)->toBe($workflow->id);
    expect($request->current_stage)->toBe(1);
    expect($request->status)->toBe(ApprovalStatus::Pending);
});

it('attaches no workflow when none is configured for the key', function () {
    $request = $this->fake->submitForApproval($this->requester);

    expect($request->workflow_id)->toBeNull();
    expect($request->totalStages())->toBe(1);
});
