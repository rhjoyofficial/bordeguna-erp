<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\SampleResult;
use App\Modules\Crm\Models\DistributorInspection;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\SupplierLeadSample;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2c, second half: what was SEEN at a distributor, and what a
 * supplier's sample turned out to be.
 *
 * ⭐⭐ JOY'S ANSWERS ON TRIAL HERE. Answer 17: before appointing a distributor
 * the ASM MAY record an inspection - date, notes, photos - and nothing waits for
 * one. Answer 15 (plan D13): samples are an append-only history, and ONLY THE
 * OWNER records a result; a re-test is a new sample.
 *
 * ⚠⚠ Most of these tests are refusals: the officer who may not inspect, the
 * ASM who may not judge, the second verdict, the edit and the delete that must
 * not exist, and the lead outside a team whose evidence - photos included -
 * must stay as hidden as the lead itself.
 *
 * The org chart: the Owner; Shoykod (Sales Lead) with Rakib under him; Stranger,
 * an officer in nobody's team; and Other ASM, a Sales Lead with no team.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Evidence Co', 'slug' => 'evidence-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $name, string $role, ?Employee $supervisor = null): array {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        $employee = Employee::create([
            'employee_code' => 'EVD-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'first_name' => $name,
            'last_name' => 'Test',
            'employment_type' => 'permanent',
            'status' => 'active',
        ]);

        EmployeeAssignment::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor?->id,
            'is_primary' => true,
            'effective_from' => '2026-01-01',
        ]);

        return [$user, $employee];
    };

    [$this->owner] = ($this->person)('Musa', 'Owner');
    [$this->asm, $asmEmployee] = ($this->person)('Shoykod', 'Sales Lead');
    [$this->rakib] = ($this->person)('Rakib', 'Sales Officer', $asmEmployee);
    [$this->stranger] = ($this->person)('Stranger', 'Sales Officer');
    [$this->otherAsm] = ($this->person)('Other ASM', 'Sales Lead');

    $this->lead = fn (string $type, User $holder, string $phone) => Lead::create([
        'business_id' => $this->business->id, 'lead_type' => $type, 'name' => ucfirst($type).' '.$holder->name,
        'phone' => $phone, 'status' => LeadStatus::New, 'assigned_to_user_id' => $holder->id,
    ]);

    $this->distributor = ($this->lead)('distributor', $this->rakib, '01711000001');
    $this->supplier = ($this->lead)('supplier', $this->rakib, '01711000002');
    $this->retailer = ($this->lead)('retailer', $this->rakib, '01711000003');
    $this->strangerDistributor = ($this->lead)('distributor', $this->stranger, '01711000004');
    $this->strangerSupplier = ($this->lead)('supplier', $this->stranger, '01711000005');

    // ⚠ The memoised team belongs to one request, and these tests switch users.
    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->timeline = fn (User $user, Lead $lead) => ($this->as)($user)
        ->get("/crm/lead/{$lead->id}/timeline")->assertOk()->viewData('page')['props'];

    $this->sample = fn (Lead $lead, string $item = 'Mustard seed, 1 kg') => SupplierLeadSample::create([
        'business_id' => $this->business->id, 'lead_id' => $lead->id, 'received_on' => '2026-09-14',
        'item' => $item, 'recorded_by' => $this->rakib->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/* -------------------------------------------------------------------------
 | Distributor inspections (answer 17)
 | ---------------------------------------------------------------------- */

it('lets the ASM record what he saw at a distributor, photos and all', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-14',
        'notes' => 'Two godowns, one rented. Three vans in the yard.',
        'photos' => [UploadedFile::fake()->image('godown.jpg', 800, 600), UploadedFile::fake()->image('vans.jpg', 800, 600)],
    ])->assertSessionHasNoErrors();

    $inspection = DistributorInspection::withoutGlobalScopes()->sole();

    expect($inspection->lead_id)->toBe($this->distributor->id)
        ->and($inspection->recorded_by)->toBe($this->asm->id)
        ->and($inspection->attachments()->where('collection', 'photos')->count())->toBe(2);

    $shown = ($this->timeline)($this->asm, $this->distributor)['inspections'];

    expect($shown)->toHaveCount(1)
        ->and($shown[0]['inspected_on'])->toBe('2026-09-14')
        ->and($shown[0]['by'])->toBe('Shoykod')
        ->and(collect($shown[0]['photos'])->pluck('name')->sort()->values()->all())->toBe(['godown.jpg', 'vans.jpg']);
});

it('keeps inspecting with the ASM, not with the officer who holds the lead', function () {
    ($this->as)($this->rakib)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-14', 'notes' => 'I went myself.',
    ])->assertForbidden();

    expect(DistributorInspection::withoutGlobalScopes()->count())->toBe(0);
});

it('records an inspection on a distributor lead only', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->retailer->id}/inspections", [
        'inspected_on' => '2026-09-14', 'notes' => 'A shop, not a distributor.',
    ])->assertSessionHasErrors(['lead_type' => 'Only a distributor lead can be inspected.']);

    expect(DistributorInspection::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an inspection that says nothing, or one dated tomorrow', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", ['inspected_on' => '2026-09-14'])
        ->assertSessionHasErrors(['notes' => 'Write what you saw, or add a photo.']);

    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-16', 'notes' => 'Not yet.',
    ])->assertSessionHasErrors('inspected_on');

    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-14', 'photos' => [UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf')],
    ])->assertSessionHasErrors('photos.0');

    expect(DistributorInspection::withoutGlobalScopes()->count())->toBe(0);
});

it('lets nobody inspect a distributor outside their team', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->strangerDistributor->id}/inspections", [
        'inspected_on' => '2026-09-14', 'notes' => 'Not mine to see.',
    ])->assertNotFound();

    expect(DistributorInspection::withoutGlobalScopes()->count())->toBe(0);
});

it('shows inspection photos only to someone who can see the lead, in the stream and in the file manager', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-14', 'photos' => [UploadedFile::fake()->image('yard.jpg', 400, 300)],
    ])->assertSessionHasNoErrors();

    $photo = Attachment::withoutGlobalScopes()->sole();

    Permission::firstOrCreate(['name' => 'files.view', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->whereIn('name', ['Sales Officer', 'Sales Lead'])->get()
        ->each->givePermissionTo('files.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $listed = fn (User $user) => collect(($this->as)($user)->get('/files')->assertOk()->viewData('page')['props']['files']['data'])
        ->pluck('filename')->all();

    expect($listed($this->rakib))->toBe(['yard.jpg'])
        ->and($listed($this->asm))->toBe(['yard.jpg'])
        ->and($listed($this->stranger))->toBe([])
        ->and($listed($this->otherAsm))->toBe([]);

    ($this->as)($this->rakib)->get(route('attachments.show', $photo))->assertOk();
    ($this->as)($this->stranger)->get(route('attachments.show', $photo))->assertNotFound();
    ($this->as)($this->otherAsm)->get(route('attachments.show', $photo))->assertNotFound();
});

it('never changes or deletes an inspection, by any path', function () {
    ($this->as)($this->asm)->post("/crm/leads/{$this->distributor->id}/inspections", [
        'inspected_on' => '2026-09-14', 'notes' => 'Two godowns.',
    ])->assertSessionHasNoErrors();

    $inspection = DistributorInspection::withoutGlobalScopes()->sole();

    expect(fn () => $inspection->update(['notes' => 'Five godowns.']))->toThrow(RuntimeException::class)
        ->and(fn () => $inspection->delete())->toThrow(RuntimeException::class)
        ->and($inspection->fresh()->notes)->toBe('Two godowns.');
});

/* -------------------------------------------------------------------------
 | Supplier samples (answer 15, plan D13)
 | ---------------------------------------------------------------------- */

it('lets the officer working a supplier log a sample that arrived', function () {
    ($this->as)($this->rakib)->post("/crm/leads/{$this->supplier->id}/samples", [
        'received_on' => '2026-09-14', 'item' => 'Mustard seed, 1 kg', 'note' => 'Came with the trader from Sirajganj.',
    ])->assertSessionHasNoErrors();

    $sample = SupplierLeadSample::withoutGlobalScopes()->sole();

    expect($sample->only(['lead_id', 'item', 'recorded_by']))->toBe([
        'lead_id' => $this->supplier->id, 'item' => 'Mustard seed, 1 kg', 'recorded_by' => $this->rakib->id,
    ])->and($sample->result)->toBeNull();
});

it('records a sample on a supplier lead only, and never one that has not arrived', function () {
    ($this->as)($this->rakib)->post("/crm/leads/{$this->distributor->id}/samples", [
        'received_on' => '2026-09-14', 'item' => 'Mustard oil, 1 L',
    ])->assertSessionHasErrors(['lead_type' => 'Samples are recorded on a supplier lead.']);

    ($this->as)($this->rakib)->post("/crm/leads/{$this->supplier->id}/samples", [
        'received_on' => '2026-09-16', 'item' => 'Mustard oil, 1 L',
    ])->assertSessionHasErrors('received_on');

    expect(SupplierLeadSample::withoutGlobalScopes()->count())->toBe(0);
});

it('lets the Owner alone say whether a sample passed', function () {
    $sample = ($this->sample)($this->supplier);

    ($this->as)($this->rakib)->post("/crm/samples/{$sample->id}/result", ['result' => 'pass'])->assertForbidden();
    ($this->as)($this->asm)->post("/crm/samples/{$sample->id}/result", ['result' => 'pass'])->assertForbidden();

    expect($sample->fresh()->result)->toBeNull();

    ($this->as)($this->owner)->post("/crm/samples/{$sample->id}/result", ['result' => 'fail', 'result_note' => 'Too much moisture.'])
        ->assertSessionHasNoErrors();

    $judged = $sample->fresh();

    expect($judged->result)->toBe(SampleResult::Fail)
        ->and($judged->result_note)->toBe('Too much moisture.')
        ->and($judged->judged_by)->toBe($this->owner->id)
        ->and($judged->judged_at?->toDateTimeString())->toBe('2026-09-15 10:00:00');
});

it('judges a sample once, and a re-test is a new sample with its own verdict', function () {
    $first = ($this->sample)($this->supplier);

    ($this->as)($this->owner)->post("/crm/samples/{$first->id}/result", ['result' => 'retest'])->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post("/crm/samples/{$first->id}/result", ['result' => 'pass'])
        ->assertSessionHasErrors(['result' => 'This sample has already been judged. A re-test is a new sample.']);

    expect($first->fresh()->result)->toBe(SampleResult::Retest);

    ($this->as)($this->rakib)->post("/crm/leads/{$this->supplier->id}/samples", [
        'received_on' => '2026-09-15', 'item' => 'Mustard seed, 1 kg (second lot)',
    ])->assertSessionHasNoErrors();
    $second = SupplierLeadSample::withoutGlobalScopes()->whereKeyNot($first->id)->sole();

    ($this->as)($this->owner)->post("/crm/samples/{$second->id}/result", ['result' => 'pass'])->assertSessionHasNoErrors();

    $history = ($this->timeline)($this->owner, $this->supplier)['samples'];

    expect(collect($history)->pluck('result', 'item')->all())->toBe([
        'Mustard seed, 1 kg (second lot)' => 'pass',
        'Mustard seed, 1 kg' => 'retest',
    ]);
});

it('never rewrites what a supplier sent, re-judges a sample or deletes one, by any path', function () {
    $sample = ($this->sample)($this->supplier);

    expect(fn () => $sample->update(['item' => 'Something better']))->toThrow(RuntimeException::class);

    // ⚠ fresh(): the refused edit is still dirty on the instance above.
    $sample->fresh()->forceFill(['result' => 'pass', 'judged_by' => $this->owner->id, 'judged_at' => now()])->save();

    expect(fn () => $sample->fresh()->forceFill(['result' => 'fail'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $sample->fresh()->delete())->toThrow(RuntimeException::class)
        ->and($sample->fresh()->only(['item']))->toBe(['item' => 'Mustard seed, 1 kg']);
});

it('keeps a sample on a hidden lead out of reach, even for somebody allowed to judge', function () {
    $hidden = ($this->sample)($this->strangerSupplier);

    // Somebody given the verdict without the Owner's view of every lead.
    Role::where('business_id', $this->business->id)->where('name', 'Sales Lead')->sole()->givePermissionTo('crm.samples.judge');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->as)($this->asm)->post("/crm/samples/{$hidden->id}/result", ['result' => 'pass'])->assertNotFound();
    ($this->as)($this->rakib)->post("/crm/leads/{$this->strangerSupplier->id}/samples", [
        'received_on' => '2026-09-14', 'item' => 'Not his supplier',
    ])->assertNotFound();

    expect($hidden->fresh()->result)->toBeNull()
        ->and(SupplierLeadSample::withoutGlobalScopes()->count())->toBe(1);
});

it('gives the verdict to the Owner and to no other role', function () {
    $holders = Role::where('business_id', $this->business->id)->get()
        ->filter(fn (Role $role) => $role->hasPermissionTo('crm.samples.judge'))
        ->pluck('name')->all();

    expect($holders)->toBe(['Owner']);
});

it('tells the timeline who may inspect and who may judge, and keeps evidence after a type change', function () {
    ($this->sample)($this->supplier);

    $asOfficer = ($this->timeline)($this->rakib, $this->supplier);
    $asOwner = ($this->timeline)($this->owner, $this->supplier);

    expect($asOfficer['canInspect'])->toBeFalse()
        ->and($asOfficer['canJudgeSample'])->toBeFalse()
        ->and($asOfficer['subject']['lead_type'])->toBe('supplier')
        ->and($asOwner['canInspect'])->toBeTrue()
        ->and($asOwner['canJudgeSample'])->toBeTrue()
        ->and(collect($asOwner['sampleResults'])->pluck('value')->all())->toBe(['pass', 'fail', 'retest']);

    // A lead corrected to another type still shows what was recorded under the old one.
    $this->supplier->update(['lead_type' => 'retailer']);

    expect(($this->timeline)($this->owner, $this->supplier->fresh())['samples'])->toHaveCount(1);
});
