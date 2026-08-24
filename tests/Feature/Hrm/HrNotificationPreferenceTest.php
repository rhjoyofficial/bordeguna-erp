<?php

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The HR notification preference (Joy, 24 Aug 2026).
 *
 * ⚠⚠ The rule worth pinning: this is NOT a permission. Moin holds every
 * permission in the business because he is the boss and may look at anything;
 * what he must not receive is HR's daily traffic. Collapsing the two would
 * have meant removing his access to quiet his inbox.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Notify Co', 'slug' => 'notify-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.leave.approve', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.leave.approve');

    $this->makeUser = function (bool $receivesHr) {
        $user = User::factory()->create(['receives_hr_notifications' => $receivesHr]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole('Owner');

        return $user;
    };

    $this->requester = User::factory()->create();
    $this->requester->businesses()->attach($this->business->id);

    $this->approvalFor = function (string $approvableType) {
        $workflow = ApprovalWorkflow::create([
            'business_id' => $this->business->id,
            'key' => 'test_flow',
            'name' => 'Test flow',
            'is_active' => true,
        ]);
        $workflow->stages()->create([
            'sequence' => 1,
            'name' => 'Approval',
            'allowed_permissions' => ['hrm.leave.approve'],
        ]);

        // ⚠ The morph columns are deliberately not fillable on this model, so
        // they are set rather than mass-assigned.
        $approval = new ApprovalRequest([
            'business_id' => $this->business->id,
            'workflow_id' => $workflow->id,
            'requested_by' => $this->requester->id,
            'current_stage' => 1,
        ]);
        $approval->approvable_type = $approvableType;
        $approval->approvable_id = 1;
        $approval->save();

        return $approval;
    };
});

it('leaves an opted-out approver out of an HR approval notification', function () {
    Notification::fake();

    $hr = ($this->makeUser)(true);
    $boss = ($this->makeUser)(false);

    app(NotifyStageApprovers::class)->handle(
        ($this->approvalFor)(\App\Modules\Hrm\Models\LeaveRequest::class),
        'Somebody asked for leave',
    );

    Notification::assertSentTo($hr, PendingApprovalNotification::class);
    Notification::assertNotSentTo($boss, PendingApprovalNotification::class);
});

// ⚠ The preference is HR's, not the whole ERP's: a stock or purchasing
// approval still reaches everyone who may act on it.
it('still notifies an opted-out approver about a non-HR approval', function () {
    Notification::fake();

    $boss = ($this->makeUser)(false);

    app(NotifyStageApprovers::class)->handle(
        ($this->approvalFor)(\App\Modules\Inventory\Models\StockRequest::class),
        'Stock request waiting',
    );

    Notification::assertSentTo($boss, PendingApprovalNotification::class);
});

it('keeps every permission while opting out of the noise', function () {
    $boss = ($this->makeUser)(false);
    setPermissionsTeamId($this->business->id);

    expect($boss->fresh()->can('hrm.leave.approve'))->toBeTrue()
        ->and($boss->fresh()->receives_hr_notifications)->toBeFalse();
});
