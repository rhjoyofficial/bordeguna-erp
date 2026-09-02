<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 5, Slice 2: HR letters.
 *
 * The system drafts, the boss signs. A letter is issued from LIVE data but
 * kept as a SNAPSHOT - a salary change next month must not silently rewrite
 * an appointment letter issued today. Print renders the snapshot, never the
 * live record.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Letter Co', 'slug' => 'letter-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 12:00:00');

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'employee_code' => 'BDG-000009',
        'first_name' => 'Rakib', 'last_name' => 'Hossain',
        'join_date' => '2026-07-01',
        'status' => 'active',
        'employment_type' => 'probation',
        'probation_months' => 3,
    ]);

    $basic = SalaryComponent::firstOrCreate(
        ['business_id' => $this->business->id, 'code' => 'BASIC'],
        ['name' => 'Basic', 'type' => 'earning', 'is_active' => true],
    );
    $structure = SalaryStructure::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-01',
        'created_by' => $this->hr->id,
    ]);
    $structure->lines()->create([
        'salary_component_id' => $basic->id,
        'value' => 15000,
    ]);

    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('issues an appointment letter as a snapshot the future cannot rewrite', function () {
    ($this->asHr)()->post(route('hrm.employees.letters.store', $this->employee), [
        'type' => 'appointment',
    ])->assertSessionHasNoErrors();

    $letter = DB::table('hr_letters')->first();
    expect($letter->reference_no)->toStartWith('LTR-')
        ->and($letter->type)->toBe('appointment');

    $snapshot = json_decode($letter->body_data, true);
    expect($snapshot['employee_name'])->toBe('Rakib Hossain')
        ->and((float) $snapshot['gross_salary'])->toBe(15000.0);

    // The future must not rewrite the paper: raise the salary, reprint.
    DB::table('salary_structure_lines')->update(['value' => 99999]);

    ($this->asHr)()->get(route('hrm.letters.print', $letter->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/letters/print')
            ->where('letter.body_data.gross_salary', fn ($value) => (float) $value === 15000.0)
            ->has('issuer.name'));
});

it('refuses an increment letter when no salary revision exists to speak of', function () {
    ($this->asHr)()->post(route('hrm.employees.letters.store', $this->employee), [
        'type' => 'increment',
    ])->assertSessionHasErrors('type');

    expect(DB::table('hr_letters')->count())->toBe(0);
});

it('writes an experience letter that respects the leaving date, and gates issuing', function () {
    $this->employee->update(['status' => 'separated', 'separation_date' => '2026-08-31']);

    ($this->asHr)()->post(route('hrm.employees.letters.store', $this->employee), [
        'type' => 'experience',
    ])->assertSessionHasNoErrors();

    $snapshot = json_decode(DB::table('hr_letters')->value('body_data'), true);
    expect($snapshot['end_date'])->toBe('2026-08-31');

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.letters.store', $this->employee), ['type' => 'experience'])
        ->assertForbidden();
});
