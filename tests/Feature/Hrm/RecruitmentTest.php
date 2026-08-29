<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\ApplicantStage;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\JobApplicant;
use App\Modules\Hrm\Models\JobOpening;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Recruitment.
 *
 * ⚠⚠ THE RULE THIS MODULE IS BUILT AROUND: marking somebody hired records a
 * DECISION and never creates an employee. An Employee carries a joining date, an
 * employment type, a probation length and a salary structure, none of which a
 * recruitment screen knows, and inventing them puts a wrong number into that
 * person's first payroll. Half the tests below exist to keep that line intact.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Hire Co', 'slug' => 'hire-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['recruitment.view', 'recruitment.manage', 'files.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['recruitment.view', 'recruitment.manage']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['recruitment.view']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->opening = JobOpening::create([
        'business_id' => $this->business->id, 'title' => 'Packer', 'openings' => 2, 'opened_on' => '2026-09-01',
    ]);
});

function applicant(int $businessId, int $openingId, array $overrides = []): JobApplicant
{
    return JobApplicant::create(array_merge([
        'business_id' => $businessId,
        'job_opening_id' => $openingId,
        'name' => 'Rahim',
        'phone' => '01711000000',
        'applied_on' => '2026-09-02',
    ], $overrides));
}

it('shows the openings and who applied for them', function () {
    applicant($this->business->id, $this->opening->id);

    $this->actingAs($this->manager)->get('/hrm/recruitment')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/recruitment/index')
            ->where('openings.0.title', 'Packer')
            ->where('openings.0.applicants_count', 1)
            ->where('applicants.total', 1)
        );
});

it('keeps a role open while any of its headcount is unfilled', function () {
    applicant($this->business->id, $this->opening->id, ['name' => 'One', 'stage' => ApplicantStage::Hired]);

    // ⚠⚠ Two openings with one hire is STILL an open role. A screen that closed
    // it the moment anybody was hired would stop the second search.
    $this->actingAs($this->manager)->get('/hrm/recruitment')
        ->assertInertia(fn ($page) => $page->where('openings.0.still_wanted', 1)->where('openings.0.openings', 2));
});

it('normalises an applicant"s number so the same person is findable however it was typed', function () {
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.store'), [
        'job_opening_id' => $this->opening->id,
        'name' => 'Rahim',
        'phone' => '+880 1711-000000',
        'applied_on' => '2026-09-02',
    ])->assertRedirect();

    // ⚠ The number as typed is never rewritten; the matching form is separate,
    // exactly as leads, customers and contacts do it.
    expect(JobApplicant::first()->phone)->toBe('+880 1711-000000')
        ->and(JobApplicant::first()->phone_normalized)->toBe('01711000000');
});

it('warns that somebody has applied before and files them anyway', function () {
    applicant($this->business->id, $this->opening->id, ['name' => 'Rahim']);

    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.store'), [
        'job_opening_id' => $this->opening->id,
        'name' => 'Rahim again',
        'phone' => '8801711000000',
        'applied_on' => '2026-09-10',
    ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'applied before'));

    // ⚠ Both kept. The same person applying for two roles is normal, and a
    // family sharing one number is as real here as it is in CRM.
    expect(JobApplicant::count())->toBe(2);
});

it('refuses an applicant nobody could contact', function () {
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.store'), [
        'job_opening_id' => $this->opening->id,
        'name' => 'Ghost',
        'applied_on' => '2026-09-02',
    ])->assertSessionHasErrors(['phone', 'email']);

    expect(JobApplicant::count())->toBe(0);
});

it('refuses to record a rejection with no reason', function () {
    $person = applicant($this->business->id, $this->opening->id);

    // ⚠⚠ A rejected pile with no reasons is a list of names nobody can explain,
    // and the same person is invited back next year. Asked at the moment it is
    // decided, because nobody ever goes back to fill it in.
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.move', $person->id), [
        'stage' => 'rejected',
    ])->assertSessionHasErrors('outcome_reason');

    expect($person->fresh()->stage)->toBe(ApplicantStage::Applied);

    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.move', $person->id), [
        'stage' => 'rejected', 'outcome_reason' => 'Wanted more than the role pays',
    ])->assertRedirect();

    expect($person->fresh()->stage)->toBe(ApplicantStage::Rejected)
        ->and($person->fresh()->outcome_reason)->toBe('Wanted more than the role pays');
});

it('marks somebody hired WITHOUT creating an employee, and says so', function () {
    $person = applicant($this->business->id, $this->opening->id);

    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.move', $person->id), ['stage' => 'hired'])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Create their employee record'));

    // ⚠⚠ THE LINE THIS MODULE MUST NOT CROSS. A joining date, an employment
    // type, a probation length and a salary structure are not things this
    // screen knows, and a guessed one is wrong money in the first payroll.
    expect(Employee::count())->toBe(0)
        ->and($person->fresh()->stage)->toBe(ApplicantStage::Hired)
        ->and($person->fresh()->hired_employee_id)->toBeNull();
});

it('links a hire to an employee record somebody made in People', function () {
    $person = applicant($this->business->id, $this->opening->id);

    // ⚠ Note what an Employee needs and a recruitment screen cannot know:
    // an employment type, and beyond it a joining date and a salary structure.
    // That is exactly why this module links to one rather than creating it.
    $employee = Employee::create([
        'employee_code' => 'EMP-1', 'first_name' => 'Rahim', 'last_name' => 'Uddin',
        'employment_type' => 'permanent',
    ]);

    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.move', $person->id), [
        'stage' => 'hired', 'hired_employee_id' => $employee->id,
    ])->assertRedirect();

    // ⚠ The link is how "who did we hire for that role" still has an answer a
    // year later.
    expect($person->fresh()->hired_employee_id)->toBe($employee->id);
});

it('drops the employee link if somebody is moved back out of hired', function () {
    $employee = Employee::create([
        'employee_code' => 'EMP-1', 'first_name' => 'Rahim', 'last_name' => 'Uddin',
        'employment_type' => 'permanent',
    ]);
    $person = applicant($this->business->id, $this->opening->id, [
        'stage' => ApplicantStage::Hired, 'hired_employee_id' => $employee->id,
    ]);

    // ⚠ Otherwise a withdrawn applicant still points at a colleague, and the
    // "who was hired for this role" answer names somebody who was not.
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.move', $person->id), [
        'stage' => 'withdrawn', 'outcome_reason' => 'Took another job',
    ])->assertRedirect();

    expect($person->fresh()->hired_employee_id)->toBeNull();
});

it('stores a CV against the applicant rather than anywhere new', function () {
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.store'), [
        'job_opening_id' => $this->opening->id,
        'name' => 'Rahim', 'phone' => '01711000000', 'applied_on' => '2026-09-02',
        'cv' => UploadedFile::fake()->create('cv.pdf', 40, 'application/pdf'),
    ])->assertRedirect();

    $person = JobApplicant::first();
    $cv = $person->attachmentInCollection('cv');

    expect($cv)->not->toBeNull();
    Storage::disk('local')->assertExists($cv->path);
});

it('refuses an executable dressed as a CV', function () {
    $this->actingAs($this->manager)->post(route('hrm.recruitment.applicants.store'), [
        'job_opening_id' => $this->opening->id,
        'name' => 'Rahim', 'phone' => '01711000000', 'applied_on' => '2026-09-02',
        'cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/octet-stream'),
    ])->assertSessionHasErrors('cv');

    expect(JobApplicant::count())->toBe(0);
});

it('lets a viewer read the pipeline and refuses to let them change it', function () {
    $person = applicant($this->business->id, $this->opening->id);

    $this->actingAs($this->staff)->get('/hrm/recruitment')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($this->staff)->post(route('hrm.recruitment.applicants.move', $person->id), ['stage' => 'screening'])
        ->assertForbidden();
});

it('never shows one business the hiring of another', function () {
    applicant($this->business->id, $this->opening->id, ['name' => 'Ours']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-hire-co']);
    $otherOpening = JobOpening::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'title' => 'Theirs', 'openings' => 1, 'opened_on' => '2026-09-01',
    ]);
    JobApplicant::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'job_opening_id' => $otherOpening->id, 'name' => 'Theirs', 'applied_on' => '2026-09-01',
    ]);

    $this->actingAs($this->manager)->get('/hrm/recruitment')
        ->assertInertia(fn ($page) => $page->where('applicants.total', 1)->where('applicants.data.0.name', 'Ours'));
});

it('refuses the page to a login that holds no recruitment permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/hrm/recruitment')->assertForbidden();
});
