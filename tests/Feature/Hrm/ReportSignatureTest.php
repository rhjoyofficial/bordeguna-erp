<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\SignatureImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Digital signatures on reports (Joy, 3 Sep 2026).
 *
 * HR uploads one signature PNG per employee (a singleton attachment, like
 * the photo). When a report is exported with the signatures box ticked, the
 * image of each named person is placed above their line - Prepared by,
 * Checked by, Approved by. No image on file leaves the line blank for ink;
 * a missing image never fails a report, exactly like the logo.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Sign Co', 'slug' => 'sign-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Storage::fake('private');

    $this->hr = User::factory()->create(['name' => 'Rakibul Hasan Joy']);
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'user_id' => $this->hr->id,
        'employee_code' => 'BDG-000001',
        'first_name' => 'Rakibul', 'last_name' => 'Hasan Joy',
        'joining_date' => '2026-07-01',
        'status' => 'active', 'employment_type' => 'permanent',
    ]);
    \App\Modules\Hrm\Models\EmployeeAssignment::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-01',
        'is_primary' => true,
    ]);

    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('lets HR upload one signature per employee, newest replacing the old', function () {
    foreach (['one.png', 'two.png'] as $name) {
        ($this->asHr)()->post(route('hrm.employees.signature.store', $this->employee), [
            'signature' => UploadedFile::fake()->image($name, 400, 120),
        ])->assertSessionHasNoErrors();
    }

    $signatures = $this->employee->fresh()->attachments()->where('collection', 'signature')->get();
    expect($signatures)->toHaveCount(1);
});

it('resolves the named people to inline signature images, and unknown names to nothing', function () {
    ($this->asHr)()->post(route('hrm.employees.signature.store', $this->employee), [
        'signature' => UploadedFile::fake()->image('sign.png', 400, 120),
    ])->assertSessionHasNoErrors();

    $meta = new ReportMeta(
        title: 'Salary sheet',
        preparedBy: 'Rakibul Hasan Joy',
        checkedBy: 'Nobody Of That Name',
        approvedBy: null,
    );

    $images = SignatureImages::forMeta($meta);

    expect($images['prepared'] ?? null)->toStartWith('data:image/')
        ->and($images['checked'] ?? null)->toBeNull()
        ->and($images['approved'] ?? null)->toBeNull();
});

it('carries the images onto the meta without disturbing anything else', function () {
    $meta = new ReportMeta(title: 'Salary sheet', preparedBy: 'Someone');

    $with = $meta->withSignatures(['prepared' => 'data:image/png;base64,AAA=']);

    expect($with->signatures['prepared'])->toBe('data:image/png;base64,AAA=')
        ->and($with->title)->toBe('Salary sheet')
        ->and($with->preparedBy)->toBe('Someone')
        // The original stays untouched - readonly means readonly.
        ->and($meta->signatures)->toBeNull();
});
