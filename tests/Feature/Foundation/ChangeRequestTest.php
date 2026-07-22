<?php

use App\Models\User;
use App\Modules\Foundation\Actions\SubmitChangeRequest;
use App\Modules\Foundation\Contracts\HandlesChangeRequest;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A stand-in ChangeRequest subject, backed by the businesses table, so the
 * generic pattern can be exercised without any HR coupling. applyChangeRequest
 * writes to the row so "the change was applied" is observable.
 */
class ChangeSubjectFake extends Model implements HandlesChangeRequest
{
    protected $table = 'businesses';

    protected $guarded = [];

    public $timestamps = false;

    public function applyChangeRequest(array $changes): void
    {
        $this->update($changes);
    }

    public function changeRequestSubjectLabel(): string
    {
        return 'Subject '.$this->getKey();
    }

    public function describeChangeRequest(array $changes): array
    {
        return collect($changes)->mapWithKeys(fn ($value, $field) => [ucfirst($field) => (string) $value])->all();
    }
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'CR Co', 'slug' => 'cr-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['HR', 'Finance', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->requester = User::factory()->create();
    $this->requester->businesses()->attach($this->business->id);

    $this->subject = ChangeSubjectFake::find($this->business->id);
});

function makeCrWorkflow(string $key, array $stages): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::create(['business_id' => test()->business->id, 'key' => $key, 'name' => $key]);
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

function crRoleUser(string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach(test()->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId(test()->business->id);
    $user->assignRole($role);

    return $user;
}

it('submits a change request onto the resolved workflow and notifies the first stage', function () {
    Notification::fake();
    makeCrWorkflow('subject_personal', [['name' => 'HR', 'roles' => ['HR']]]);
    $hr = crRoleUser('HR');

    $cr = app(SubmitChangeRequest::class)->handle(
        subject: $this->subject,
        changes: ['name' => 'Renamed'],
        workflowKey: 'subject_personal',
        requestedBy: $this->requester,
        businessId: $this->business->id,
    );

    expect($cr->approvalRequest->workflow->key)->toBe('subject_personal');
    expect($cr->approvalRequest->status)->toBe(ApprovalStatus::Pending);
    // The proposed changes must be readable from inside the model (regression:
    // a "changes" column is shadowed by Eloquent's internal $changes property).
    expect($cr->proposed_changes)->toBe(['name' => 'Renamed']);
    expect($cr->describedChanges())->toBe(['Name' => 'Renamed']);
    Notification::assertSentTo($hr, PendingApprovalNotification::class);
});

it('applies the change through the subject applier when a single-stage request is approved', function () {
    makeCrWorkflow('subject_personal', [['name' => 'HR', 'roles' => ['HR']]]);
    $hr = crRoleUser('HR');
    $cr = app(SubmitChangeRequest::class)->handle($this->subject, ['name' => 'Applied Name'], 'subject_personal', $this->requester, $this->business->id);

    $this->actingAs($hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('change-requests.approve', $cr))
        ->assertRedirect();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect(Business::find($this->business->id)->name)->toBe('Applied Name'); // subject applier ran
});

it('advances a two-stage request, notifying the next stage, and applies only at the end', function () {
    Notification::fake();
    makeCrWorkflow('subject_bank', [
        ['name' => 'HR', 'roles' => ['HR']],
        ['name' => 'Finance', 'roles' => ['Finance']],
    ]);
    $hr = crRoleUser('HR');
    $finance = crRoleUser('Finance');
    $cr = app(SubmitChangeRequest::class)->handle($this->subject, ['name' => 'Two Stage'], 'subject_bank', $this->requester, $this->business->id);

    // Stage 1 approval: advances, does NOT apply yet, notifies Finance.
    $this->actingAs($hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('change-requests.approve', $cr))->assertRedirect();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Pending);
    expect($cr->approvalRequest->fresh()->current_stage)->toBe(2);
    expect(Business::find($this->business->id)->name)->toBe('CR Co'); // not applied yet
    Notification::assertSentTo($finance, PendingApprovalNotification::class);

    // Stage 2 approval: completes and applies.
    $this->actingAs($finance)->withSession(['current_business_id' => $this->business->id])
        ->post(route('change-requests.approve', $cr))->assertRedirect();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Approved);
    expect(Business::find($this->business->id)->name)->toBe('Two Stage');
});

it('forbids a user who is not on the current stage from approving', function () {
    makeCrWorkflow('subject_bank', [
        ['name' => 'HR', 'roles' => ['HR']],
        ['name' => 'Finance', 'roles' => ['Finance']],
    ]);
    $finance = crRoleUser('Finance'); // stage 2, cannot act on stage 1 yet
    $cr = app(SubmitChangeRequest::class)->handle($this->subject, ['name' => 'X'], 'subject_bank', $this->requester, $this->business->id);

    $this->actingAs($finance)->withSession(['current_business_id' => $this->business->id])
        ->post(route('change-requests.approve', $cr))
        ->assertForbidden();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('rejects a change request without applying it', function () {
    makeCrWorkflow('subject_personal', [['name' => 'HR', 'roles' => ['HR']]]);
    $hr = crRoleUser('HR');
    $cr = app(SubmitChangeRequest::class)->handle($this->subject, ['name' => 'Nope'], 'subject_personal', $this->requester, $this->business->id);

    $this->actingAs($hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('change-requests.reject', $cr), ['review_note' => 'Denied'])
        ->assertRedirect();

    expect($cr->approvalRequest->fresh()->status)->toBe(ApprovalStatus::Rejected);
    expect($cr->approvalRequest->fresh()->review_note)->toBe('Denied');
    expect(Business::find($this->business->id)->name)->toBe('CR Co'); // untouched
});
