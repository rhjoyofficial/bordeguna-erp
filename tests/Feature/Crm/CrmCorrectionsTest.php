<?php

use App\Models\User;
use App\Modules\Crm\Actions\LogCrmActivity;
use App\Modules\Crm\Corrections\CrmActivityActionPolicy;
use App\Modules\Crm\Corrections\LeadActionPolicy;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\GoingCold;
use App\Modules\Crm\Support\LeadPresenter;
use App\Modules\Crm\Support\PartyUsage;
use App\Modules\Foundation\Corrections\ActionReasons;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\RecordHistory;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 3, CRM (24 Oct 2026; FINAL-PLAN 3.2 and 6.3):
 *
 *   a call, visit or note   the author's Edit and Delete within 24 hours,
 *                           Correct after that (the old one kept, struck out)
 *   a lead                  Reopen (lost), Reverse conversion (while the
 *                           customer or supplier is unused), Delete (the bin)
 *   a deal                  Reopen
 *
 * Every action needs a reason on the server (except editing your own fresh
 * entry), keeps the officer's own-leads rule, and refuses a second click.
 * Phone book contacts and imports are in CrmPhoneBookCorrectionsTest.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 10:00:00');
    PartyUsage::forget();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new FoundationPermissionsSeeder)->run();
    (new CrmPermissionsSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    ActionReasons::seedFor($this->business->id);

    $this->person = function (string $role, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->person)('Owner', 'Joy');
    $this->manager = ($this->person)('Manager', 'Moin');
    $this->officer = ($this->person)('Sales Officer', 'Rafiq');
    $this->colleague = ($this->person)('Sales Officer', 'Karim');

    // The ASM over both officers (his team comes from HRM; here, everything).
    $this->manager->givePermissionTo('crm.leads.view.all');
    Role::where('name', 'Owner')->where('business_id', $this->business->id)->first()->givePermissionTo('recycle_bin.manage');

    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->lead = fn (array $extra = []) => Lead::withoutGlobalScopes()->find(Lead::create(array_merge([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => 'Rahim Store',
        'phone' => '01713'.random_int(100000, 999999), 'status' => LeadStatus::Contacted,
        'thana' => 'Shahjadpur', 'district' => 'Sirajganj', 'assigned_to_user_id' => $this->officer->id,
    ], $extra))->id);

    $this->log = function (Lead $lead, User $by, string $summary = 'Asked for the price list', ActivityType $type = ActivityType::Call): CrmActivity {
        $this->actingAs($by);

        return app(LogCrmActivity::class)->handle($lead, $type, $summary, $by, now()->subHour());
    };

    $this->reason = ['reason_text' => 'Typed on the wrong lead'];
});

afterEach(fn () => Carbon::setTestNow());

// ── Calls, visits and notes ─────────────────────────────────────────────────

it('lets the author edit a fresh entry without a reason, keeps the before and after, and marks it Edited', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    ($this->as)($this->officer)->put("/crm/activities/{$call->id}", [
        'summary' => 'Asked for the price list and samples', 'occurred_at' => '2026-10-24 09:00:00', 'seen' => $call->updated_at->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $call = CrmActivity::query()->find($call->id);
    $history = collect(RecordHistory::for($call, $this->owner)['entries']);

    expect($call->summary)->toBe('Asked for the price list and samples')
        ->and($call->edited_at)->not->toBeNull()
        ->and($call->type)->toBe(ActivityType::Call)
        ->and($history->firstWhere('kind', 'changed')['before']['summary'])->toBe('Asked for the price list')
        ->and($history->firstWhere('kind', 'changed')['after']['summary'])->toBe('Asked for the price list and samples');
});

it('refuses anybody but the author, an edit after 24 hours, a stale form and a date in the future', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    // The manager sees the lead, but the account is the officer's own.
    ($this->as)($this->manager)->put("/crm/activities/{$call->id}", ['summary' => 'Rewritten'])
        ->assertSessionHasErrors(['activity' => 'Only Rafiq, who recorded it, can change it. Add a new entry instead.']);

    ($this->as)($this->officer)->put("/crm/activities/{$call->id}", ['summary' => 'Later', 'seen' => '2026-01-01 00:00:00'])
        ->assertSessionHasErrors('stale');

    ($this->as)($this->officer)->put("/crm/activities/{$call->id}", ['summary' => 'Later', 'occurred_at' => '2026-10-25 09:00:00'])
        ->assertSessionHasErrors('occurred_at');

    Carbon::setTestNow('2026-10-25 10:00:01');

    ($this->as)($this->officer)->put("/crm/activities/{$call->id}", ['summary' => 'Too late'])
        ->assertSessionHasErrors(['activity' => 'More than 24 hours have passed since it was recorded. Correct it instead.']);

    expect(CrmActivity::query()->find($call->id)->summary)->toBe('Asked for the price list');
});

it('moves a fresh entry to the Recycle bin with a reason, out of the last contact, and the Owner restores it', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", ['reason_text' => 'abc '])->assertSessionHasErrors('reason_text');
    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", $this->reason)->assertSessionHasNoErrors();

    $binned = CrmActivity::onlyTrashed()->sole();
    $row = LeadPresenter::withListColumns(Lead::query())->whereKey($lead->id)->first();

    expect($binned->deleted_by)->toBe($this->officer->id)
        ->and($binned->delete_reason)->toBe('Typed on the wrong lead')
        ->and($row->last_contact_at)->toBeNull();

    // A second click finds nothing to delete.
    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", $this->reason)->assertNotFound();

    ($this->as)($this->owner)->post("/settings/recycle-bin/crm.activity/{$call->id}/restore", ['reason_text' => 'It was the right lead'])->assertSessionHas('success');

    expect(CrmActivity::query()->find($call->id))->not->toBeNull();
});

it('corrects an entry after 24 hours: a new entry replaces it, the old one stays struck out, and only once', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/correct", ['summary' => 'Fixed'] + $this->reason)
        ->assertSessionHasErrors(['activity' => 'It was recorded less than 24 hours ago. Edit it instead.']);

    Carbon::setTestNow('2026-10-26 10:00:00');

    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/correct", ['summary' => 'Asked for the price list of mustard oil'])
        ->assertSessionHasErrors('reason_text');
    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", $this->reason)
        ->assertSessionHasErrors(['activity' => 'More than 24 hours have passed since it was recorded. Correct it instead.']);

    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/correct", [
        'summary' => 'Asked for the price list of mustard oil', 'occurred_at' => '2026-10-24 08:00:00', 'reason_text' => 'Wrong product named',
    ])->assertSessionHasNoErrors();

    $old = CrmActivity::query()->find($call->id);
    $new = CrmActivity::query()->where('correction_of_id', $call->id)->sole();
    $row = LeadPresenter::withListColumns(Lead::query())->whereKey($lead->id)->first();

    expect($old->summary)->toBe('Asked for the price list')
        ->and($old->corrected_at)->not->toBeNull()
        ->and($old->correction_reason)->toBe('Wrong product named')
        ->and($new->summary)->toBe('Asked for the price list of mustard oil')
        ->and($new->type)->toBe(ActivityType::Call)
        ->and($new->recorded_by)->toBe($this->officer->id)
        ->and(Carbon::parse($row->last_contact_at)->toDateTimeString())->toBe('2026-10-24 08:00:00')
        ->and(collect(RecordHistory::for($old, $this->owner)['entries'])->pluck('kind'))->toContain('replaced_by_a_correction');

    // The double click is refused; the newer entry is the one to correct.
    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/correct", ['summary' => 'Again'] + $this->reason)
        ->assertSessionHasErrors(['activity' => 'This entry was already corrected. Correct the newer entry instead.']);

    // A correction stands for the old entry, so it is edited, never binned.
    ($this->as)($this->officer)->post("/crm/activities/{$new->id}/delete", $this->reason)
        ->assertSessionHasErrors(['activity' => 'This entry corrects an earlier one. Edit it instead.']);

    expect(CrmActivity::query()->count())->toBe(2);
});

it('keeps an officer out of another officer\'s lead entries, and the model guard still refuses a direct change', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    ($this->as)($this->colleague)->put("/crm/activities/{$call->id}", ['summary' => 'Mine now'])->assertNotFound();
    ($this->as)($this->colleague)->post("/crm/activities/{$call->id}/delete", $this->reason)->assertNotFound();

    expect(fn () => $call->forceFill(['summary' => 'Quietly'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $call->delete())->toThrow(RuntimeException::class);
});

it('offers Edit and Delete inside the 24 hours, Correct after, and nothing on a corrected entry', function () {
    $policy = app(CrmActivityActionPolicy::class);
    $call = ($this->log)(($this->lead)(), $this->officer);

    $fresh = $policy->actionsFor($call, $this->officer);
    expect($fresh->stage)->toBe(DocumentStage::Draft)
        ->and(array_map(fn ($a) => $a->action, $fresh->actions))->toBe([DocumentAction::Edit, DocumentAction::Delete])
        ->and($fresh->get(DocumentAction::Edit)->requiresReason)->toBeFalse();

    Carbon::setTestNow('2026-10-26 10:00:00');
    $old = $policy->actionsFor($call->fresh(), $this->officer);
    expect($old->stage)->toBe(DocumentStage::Done)
        ->and(array_map(fn ($a) => $a->action, $old->actions))->toBe([DocumentAction::Correct]);
});

// ── Leads ───────────────────────────────────────────────────────────────────

it('reopens a lost lead with a reason: its status before, and the lost date and reason cleared', function () {
    $lost = Reason::create(['business_id' => $this->business->id, 'context' => 'lead_lost', 'name' => 'Price too high', 'is_active' => true]);
    $lead = ($this->lead)();

    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ['name' => 'Rahim Store', 'phone' => $lead->phone, 'lead_type' => 'retailer',
        'district' => 'Sirajganj', 'thana' => 'Shahjadpur', 'status' => 'lost', 'lost_reason_id' => $lost->id])->assertSessionHasNoErrors();

    // Leaving Lost through the form is refused: Reopen asks why.
    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ['name' => 'Rahim Store', 'phone' => $lead->phone, 'lead_type' => 'retailer',
        'district' => 'Sirajganj', 'thana' => 'Shahjadpur', 'status' => 'qualified'])
        ->assertSessionHasErrors(['status' => 'This lead is marked lost. Reopen it, with a reason, to work it again.']);

    ($this->as)($this->officer)->post("/crm/leads/{$lead->id}/reopen", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->officer)->post("/crm/leads/{$lead->id}/reopen", ['reason_text' => 'He called back, price agreed'])->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->find($lead->id);

    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->lost_at)->toBeNull()
        ->and($lead->lost_reason_id)->toBeNull()
        ->and(collect(RecordHistory::for($lead, $this->owner)['entries'])->firstWhere('kind', 'reopened')['reason'])->toBe('He called back, price agreed');

    ($this->as)($this->officer)->post("/crm/leads/{$lead->id}/reopen", ['reason_text' => 'Clicked twice by mistake'])
        ->assertSessionHasErrors(['lead' => 'This lead is not marked lost, so there is nothing to reopen.']);

    // Another officer's lead does not exist for him.
    ($this->as)($this->colleague)->post("/crm/leads/{$lead->id}/reopen", ['reason_text' => 'Not my lead at all'])->assertNotFound();
});

it('reverses a conversion that made a new customer: switched off, the lead back to its status before', function () {
    $lead = ($this->lead)(['status' => LeadStatus::Qualified]);

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/convert", ['code' => 'RAHIM', 'name' => 'Rahim Store'])->assertSessionHasNoErrors();
    $customer = Customer::query()->where('code', 'RAHIM')->sole();

    // The officer converts nothing, so he reverses nothing.
    ($this->as)($this->officer)->post("/crm/leads/{$lead->id}/reverse-conversion", $this->reason)
        ->assertSessionHasErrors(['lead' => 'You are not allowed to reverse this record.']);

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/reverse-conversion", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/reverse-conversion", ['reason_text' => 'Converted the wrong shop'])->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->find($lead->id);

    expect($lead->converted_customer_id)->toBeNull()
        ->and($lead->converted_at)->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::Qualified)
        ->and($customer->fresh()->is_active)->toBeFalse()
        ->and(Customer::query()->where('code', 'RAHIM')->exists())->toBeTrue();

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/reverse-conversion", ['reason_text' => 'Clicked twice'])
        ->assertSessionHasErrors(['lead' => 'This lead was not converted, so there is no conversion to reverse.']);
});

it('only unlinks a customer that existed before, and refuses once anything is recorded against the customer', function () {
    $existing = Customer::create(['business_id' => $this->business->id, 'code' => 'OLD', 'name' => 'Old Customer', 'is_active' => true]);
    Carbon::setTestNow('2026-10-24 11:00:00');
    $lead = ($this->lead)();

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/convert", ['customer_id' => $existing->id])->assertSessionHasNoErrors();

    DB::table('quotations')->insert([
        'business_id' => $this->business->id, 'quotation_number' => 'QT-1', 'customer_id' => $existing->id,
        'valid_until' => '2026-11-01', 'created_by' => $this->manager->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/reverse-conversion", $this->reason)
        ->assertSessionHasErrors(['lead' => 'The customer Old Customer already appears in quotations, so the conversion cannot be reversed.']);

    DB::table('quotations')->delete();

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/reverse-conversion", $this->reason)->assertSessionHasNoErrors();

    expect($existing->fresh()->is_active)->toBeTrue()
        ->and(Lead::withoutGlobalScopes()->find($lead->id)->converted_customer_id)->toBeNull();
});

it('reverses a supplier conversion only for somebody who may make suppliers', function () {
    $lead = ($this->lead)(['lead_type' => 'supplier', 'status' => LeadStatus::Qualified]);

    ($this->as)($this->owner)->post("/crm/leads/{$lead->id}/convert", ['code' => 'SMT', 'name' => 'Mustard Traders'])->assertSessionHasNoErrors();

    // A Sales Lead converts shops, not suppliers.
    $salesLead = ($this->person)('Sales Lead', 'Nasir');
    $salesLead->givePermissionTo('crm.leads.view.all');
    ($this->as)($salesLead)->post("/crm/leads/{$lead->id}/reverse-conversion", $this->reason)
        ->assertSessionHasErrors(['lead' => 'You are not allowed to reverse this record.']);

    ($this->as)($this->owner)->post("/crm/leads/{$lead->id}/reverse-conversion", $this->reason)->assertSessionHasNoErrors();

    expect(Supplier::query()->where('code', 'SMT')->sole()->is_active)->toBeFalse();
});

it('moves an unworked lead to the Recycle bin with its open follow-ups cancelled, and keeps its number reserved', function () {
    $lead = ($this->lead)(['phone' => '01711000001']);
    CrmTask::create(['business_id' => $this->business->id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'title' => 'First call', 'due_at' => now()->addDay(), 'status' => TaskStatus::Open, 'assigned_to_user_id' => $this->officer->id, 'created_by' => $this->officer->id]);

    // The officer cannot bin a shop, not even his own (the ASM's call, like Archive).
    ($this->as)($this->officer)->post("/crm/leads/{$lead->id}/delete", $this->reason)
        ->assertSessionHasErrors(['lead' => 'You are not allowed to delete this record.']);

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/delete", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/delete", ['reason_text' => 'Entered twice'])->assertRedirect('/crm/leads');

    expect(Lead::withoutGlobalScopes()->whereKey($lead->id)->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(CrmTask::query()->sole()->status)->toBe(TaskStatus::Cancelled);

    // Its number stays taken while it is in the bin.
    ($this->as)($this->manager)->post('/crm/leads', ['name' => 'Rahim Again', 'phone' => '01711000001', 'lead_type' => 'retailer',
        'district' => 'Sirajganj', 'thana' => 'Shahjadpur', 'status' => 'new'])
        ->assertSessionHasErrors(['phone' => 'A deleted lead has this number. Ask the Owner to restore it from the Recycle bin.']);

    ($this->as)($this->manager)->post("/settings/recycle-bin/crm.lead/{$lead->id}/restore", ['reason_text' => 'Needed it'])->assertForbidden();
    ($this->as)($this->owner)->post("/settings/recycle-bin/crm.lead/{$lead->id}/restore", ['reason_text' => 'It was not a duplicate'])->assertSessionHas('success');

    expect(Lead::query()->whereKey($lead->id)->exists())->toBeTrue();
});

it('refuses to bin a lead that was worked, a converted one, or one outside the officer\'s team', function () {
    $worked = ($this->lead)();
    ($this->log)($worked, $this->officer);

    ($this->as)($this->manager)->post("/crm/leads/{$worked->id}/delete", $this->reason)
        ->assertSessionHasErrors(['lead' => 'Calls, visits or notes are recorded on this lead. Archive it instead.']);

    $converted = ($this->lead)(['status' => LeadStatus::Qualified]);
    ($this->as)($this->manager)->post("/crm/leads/{$converted->id}/convert", ['code' => 'C1', 'name' => 'C One'])->assertSessionHasNoErrors();

    ($this->as)($this->manager)->post("/crm/leads/{$converted->id}/delete", $this->reason)
        ->assertSessionHasErrors(['lead' => 'This lead was converted. Reverse the conversion first.']);

    $theirs = ($this->lead)(['assigned_to_user_id' => $this->colleague->id]);
    ($this->as)($this->officer)->post("/crm/leads/{$theirs->id}/delete", $this->reason)->assertNotFound();

    expect(Lead::withoutGlobalScopes()->count())->toBe(3);
});

it('puts Reverse on a converted lead and Delete on a working one, never both', function () {
    $policy = app(LeadActionPolicy::class);
    $lead = ($this->lead)(['status' => LeadStatus::Qualified]);

    expect(array_map(fn ($a) => $a->action, $policy->actionsFor($lead, $this->manager)->actions))->toBe([DocumentAction::Delete])
        ->and($policy->actionsFor($lead, $this->officer)->actions)->toBe([]);

    ($this->as)($this->manager)->post("/crm/leads/{$lead->id}/convert", ['code' => 'C2', 'name' => 'C Two'])->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->find($lead->id);

    expect(array_map(fn ($a) => $a->action, $policy->actionsFor($lead, $this->manager)->actions))->toBe([DocumentAction::Reverse])
        ->and(app(DocumentPolicyRegistry::class)->has(LeadActionPolicy::TYPE))->toBeTrue();

    // The generic money route refuses a lead with a sentence, never posts.
    ($this->as)($this->manager)->postJson("/records/crm.lead/{$lead->id}/reverse/preview", ['reason_text' => 'Wrong shop', 'idempotency_key' => str_repeat('k', 20)])
        ->assertStatus(422);
});

// ── Deals ───────────────────────────────────────────────────────────────────

it('reopens a won or lost deal with a reason, back on the board where it was', function () {
    $stage = DB::table('crm_pipeline_stages')->insertGetId(['business_id' => $this->business->id, 'name' => 'Proposal', 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $lead = ($this->lead)();
    $deal = DB::table('crm_opportunities')->insertGetId([
        'business_id' => $this->business->id, 'name' => 'Mustard oil, 40 tins', 'lead_id' => $lead->id, 'stage_id' => $stage,
        'value' => 50000, 'assigned_to_user_id' => $this->officer->id, 'status' => 'lost', 'lost_at' => now(), 'lost_reason_id' => null,
        'created_by' => $this->officer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->as)($this->officer)->post("/crm/opportunities/{$deal}/reopen", [])->assertSessionHasErrors('reason_text');
    ($this->as)($this->colleague)->post("/crm/opportunities/{$deal}/reopen", ['reason_text' => 'Not mine but trying'])->assertNotFound();
    ($this->as)($this->officer)->post("/crm/opportunities/{$deal}/reopen", ['reason_text' => 'He came back with a better offer'])->assertSessionHasNoErrors();

    $row = DB::table('crm_opportunities')->find($deal);

    expect($row->status)->toBe('open')
        ->and($row->lost_at)->toBeNull()
        ->and((int) $row->stage_id)->toBe($stage);

    ($this->as)($this->officer)->post("/crm/opportunities/{$deal}/reopen", ['reason_text' => 'Clicked twice'])
        ->assertSessionHasErrors(['opportunity' => 'This deal is already open on the board.']);
});

it('counts a lead as going cold from its last live entry, not a binned or corrected one', function () {
    $lead = ($this->lead)();
    $call = ($this->log)($lead, $this->officer);

    ($this->as)($this->officer)->post("/crm/activities/{$call->id}/delete", $this->reason)->assertSessionHasNoErrors();

    $touch = Lead::query()->whereKey($lead->id)->selectRaw(GoingCold::lastTouchSql().' as touched', [$lead->getMorphClass()])->value('touched');

    expect(Carbon::parse($touch)->toDateTimeString())->toBe($lead->created_at->toDateTimeString());
});
