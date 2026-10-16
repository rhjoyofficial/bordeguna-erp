<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\CalendarFeed;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2a: whose leads a person may see.
 *
 * ⭐⭐ JOY'S ANSWER, 14 SEP 2026, ON TRIAL HERE: only the Owner sees every lead;
 * the ASM sees his team's through the org chart; an officer sees his own. Build
 * plan D1-D4 (approved 15 Sep): an officer's new lead is his, the team is the
 * whole reporting tree below, view-all is the Owner's alone, and a number held
 * by a lead the reader cannot see is reported by its holder's name only.
 *
 * ⚠⚠ The rule is a global scope, so most of these tests exist to prove that
 * every PATH a URL can reach goes through it - the list, the timeline, the
 * writes, tasks and interests reached by their own ids, search, the pipeline,
 * the phone-book pull, the reports, the calendar and the files. A guard that one
 * path skips is not a guard.
 *
 * The org chart mirrors production (15 Sep 2026): a head above the ASM, the
 * ASM (Shoykod) with two officers (Rakib, Nazmul), plus an officer in nobody's
 * team and a Manager with no team at all.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    $this->business = Business::create(['name' => 'Field Co', 'slug' => 'field-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    // ⚠ Creates Sales Officer and Sales Lead itself - nothing here makes them.
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $name, string $role, ?Employee $supervisor = null): array {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        $employee = Employee::create([
            'employee_code' => 'FLD-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
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
    [$this->head, $headEmployee] = ($this->person)('Head', 'Sales Lead', null);
    [$this->asm, $asmEmployee] = ($this->person)('Shoykod', 'Sales Lead', $headEmployee);
    [$this->rakib] = ($this->person)('Rakib', 'Sales Officer', $asmEmployee);
    [$this->nazmul] = ($this->person)('Nazmul', 'Sales Officer', $asmEmployee);
    [$this->stranger] = ($this->person)('Stranger', 'Sales Officer', null);

    // A Manager with no employee record: may assign, has no team, is not Owner.
    $this->manager = User::factory()->create(['name' => 'Manager']);
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $shop = fn (string $name, string $phone, ?User $holder) => Lead::create([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => $name,
        'phone' => $phone, 'status' => LeadStatus::New, 'assigned_to_user_id' => $holder?->id,
    ]);

    $this->rakibShop = $shop('Rakib Store', '01711000001', $this->rakib);
    $this->nazmulShop = $shop('Nazmul Store', '01711000002', $this->nazmul);
    $this->strangerShop = $shop('Stranger Store', '01711000003', $this->stranger);
    $this->unassignedShop = $shop('Nobody Store', '01711000004', null);

    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->listFor = function (User $user): array {
        // ⚠ The memoised team belongs to one request; tests reuse the app.
        app()->forgetScopedInstances();

        return collect(($this->as)($user)->get('/crm/leads')->assertOk()->viewData('page')['props']['leads']['data'])
            ->pluck('name')->sort()->values()->all();
    };
});

afterEach(fn () => Carbon::setTestNow());

/* -------------------------------------------------------------------------
 | The roles
 | ---------------------------------------------------------------------- */

it('creates the two field-sales roles with exactly the permissions Joy decided', function () {
    $permissions = fn (string $role) => Role::where('business_id', $this->business->id)->where('name', $role)->sole()
        ->permissions->pluck('name')->sort()->values()->all();

    // ⭐ 26 Sep 2026 (Q21): both may also take a sales order, which waits for a Manager.
    expect($permissions('Sales Officer'))->toBe(['crm.leads.manage', 'crm.leads.view', 'sales.orders.record'])
        ->and($permissions('Sales Lead'))->toBe(['crm.leads.assign', 'crm.leads.convert', 'crm.leads.manage', 'crm.leads.verify', 'crm.leads.view', 'sales.orders.record']);
});

it('gives view-all to the Owner and to nobody else', function () {
    $holders = Role::where('business_id', $this->business->id)->get()
        ->filter(fn (Role $role) => $role->hasPermissionTo('crm.leads.view.all'))
        ->pluck('name')->values()->all();

    expect($holders)->toBe(['Owner']);
});

it('is wired into the seeder a production deploy actually runs', function () {
    // ⚠⚠ Found 15 Sep 2026: this seeder ran only in the sandbox, so the
    // documented `db:seed --force` never reached CRM. Structural on purpose -
    // DatabaseSeeder cannot run under test without the whole organisation.
    expect(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))
        ->toContain('$this->call(CrmPermissionsSeeder::class);');
});

it('seeds twice without duplicating a role', function () {
    (new CrmPermissionsSeeder)->run();

    expect(Role::where('business_id', $this->business->id)->where('name', 'Sales Officer')->count())->toBe(1);
});

/* -------------------------------------------------------------------------
 | Who sees what on the list
 | ---------------------------------------------------------------------- */

it('shows the Owner every lead', function () {
    expect(($this->listFor)($this->owner))->toBe(['Nazmul Store', 'Nobody Store', 'Rakib Store', 'Stranger Store']);
});

it('shows an officer only his own leads', function () {
    expect(($this->listFor)($this->rakib))->toBe(['Rakib Store']);
});

it('shows the ASM his team and the unassigned leads he hands out, and nobody else', function () {
    expect(($this->listFor)($this->asm))->toBe(['Nazmul Store', 'Nobody Store', 'Rakib Store']);
});

it('shows the whole branch below, not only direct reports', function () {
    // ⚠ Build plan D2. Rakib reports to Shoykod, who reports to Head.
    expect(($this->listFor)($this->head))->toBe(['Nazmul Store', 'Nobody Store', 'Rakib Store']);
});

it('does not give a Manager every lead any more', function () {
    // ⚠ Build plan D3. Manager may assign, so sees the unassigned; no team, so
    // nothing else.
    expect(($this->listFor)($this->manager))->toBe(['Nobody Store']);
});

it('follows the org chart the day it changes, with nothing to sync', function () {
    EmployeeAssignment::where('employee_id', Employee::forUser($this->rakib)->id)
        ->update(['effective_to' => '2026-09-14']);

    expect(($this->listFor)($this->asm))->toBe(['Nazmul Store', 'Nobody Store']);
});

it('gives an officer the lead he captures, and records that he captured it', function () {
    ($this->as)($this->rakib)->post('/crm/leads', [
        'name' => 'New Shop', 'phone' => '01711000009', 'lead_type' => 'retailer', 'status' => 'new', 'district' => 'Dhaka', 'thana' => 'Mirpur',
    ])->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->where('name', 'New Shop')->sole();

    expect($lead->assigned_to_user_id)->toBe($this->rakib->id)
        ->and($lead->created_by_user_id)->toBe($this->rakib->id)
        ->and(($this->listFor)($this->rakib))->toBe(['New Shop', 'Rakib Store']);
});

it('narrows nothing when there is no signed-in person, so jobs see every lead', function () {
    // ⚠ The scheduler and the cadence runner have no user. The tenant boundary
    // still holds underneath: BusinessScope is untouched.
    expect(auth()->check())->toBeFalse()
        ->and(Lead::count())->toBe(4);
});

/* -------------------------------------------------------------------------
 | Every path a URL can reach
 | ---------------------------------------------------------------------- */

it('refuses a colleague\'s lead on every lead route', function (string $method, Closure $url, array $body) {
    ($this->as)($this->rakib)->json($method, $url($this->nazmulShop), $body)->assertNotFound();
})->with([
    'timeline' => ['GET', fn (Lead $l) => "/crm/lead/{$l->id}/timeline", []],
    'edit' => ['PUT', fn (Lead $l) => "/crm/leads/{$l->id}", ['status' => 'contacted']],
    'record a visit' => ['POST', fn (Lead $l) => "/crm/lead/{$l->id}/activities", ['type' => 'visit', 'summary' => 'x']],
    'add a follow-up' => ['POST', fn (Lead $l) => "/crm/lead/{$l->id}/tasks", ['title' => 'x', 'due_at' => '2026-09-16 10:00:00']],
    'record an interest' => ['POST', fn (Lead $l) => "/crm/lead/{$l->id}/interests", ['note' => 'x']],
]);

it('refuses a lead outside his team on every ASM route, even with the permission', function (string $method, Closure $url) {
    ($this->as)($this->asm)->json($method, $url($this->strangerShop), ['survivor_id' => $this->rakibShop->id, 'code' => 'X'])
        ->assertNotFound();

    expect($this->strangerShop->fresh()->verified_at)->toBeNull()
        ->and($this->strangerShop->fresh()->archived_at)->toBeNull()
        ->and($this->strangerShop->fresh()->converted_customer_id)->toBeNull();
})->with([
    'verify' => ['POST', fn (Lead $l) => "/crm/leads/{$l->id}/verify"],
    'archive' => ['POST', fn (Lead $l) => "/crm/leads/{$l->id}/archive"],
    'convert' => ['POST', fn (Lead $l) => "/crm/leads/{$l->id}/convert"],
    'link as duplicate' => ['POST', fn (Lead $l) => "/crm/leads/{$l->id}/duplicate-of"],
]);

it('refuses a follow-up on a colleague\'s lead, reached by the task\'s own id', function () {
    $task = CrmTask::create([
        'business_id' => $this->business->id, 'subject_type' => Lead::class, 'subject_id' => $this->nazmulShop->id,
        'title' => 'Call Nazmul Store', 'due_at' => '2026-09-16 10:00:00',
        'assigned_to_user_id' => $this->nazmul->id, 'status' => TaskStatus::Open, 'created_by' => $this->nazmul->id,
    ]);

    ($this->as)($this->rakib)->put("/crm/tasks/{$task->id}", ['status' => 'done'])->assertNotFound();

    expect($task->fresh()->status)->toBe(TaskStatus::Open);
});

it('refuses an interest on a colleague\'s lead, reached by the interest\'s own id', function () {
    ($this->as)($this->nazmul)->post("/crm/lead/{$this->nazmulShop->id}/interests", [
        'note' => 'Wants honey',
        'brand_id' => DB::table('brands')->insertGetId([
            'business_id' => $this->business->id, 'code' => 'BDG', 'name' => 'Bor de Guna', 'slug' => 'bdg',
            'created_at' => now(), 'updated_at' => now(),
        ]),
    ])->assertSessionHasNoErrors();

    $interestId = DB::table('crm_product_interests')->value('id');
    expect($interestId)->not->toBeNull();

    ($this->as)($this->rakib)->put("/crm/interests/{$interestId}/close", ['status' => 'fulfilled'])->assertNotFound();
});

it('lets an officer who can see his lead still not link duplicates, which is the ASM\'s', function () {
    ($this->as)($this->rakib)->post("/crm/leads/{$this->rakibShop->id}/duplicate-of", ['survivor_id' => $this->rakibShop->id])
        ->assertForbidden();
});

it('finds only visible leads in the header search', function () {
    app()->forgetScopedInstances();

    $groups = ($this->as)($this->rakib)->getJson('/search?q=Store')->assertOk()->json('groups');
    $leads = collect($groups)->firstWhere('title', 'Leads');

    expect(collect($leads['items'])->pluck('label')->all())->toBe(['Rakib Store'])
        ->and($leads['total'])->toBe(1);
});

it('keeps a colleague\'s deal off the pipeline and out of reach', function () {
    $stage = DB::table('crm_pipeline_stages')->insertGetId([
        'business_id' => $this->business->id, 'name' => 'Qualification', 'sort_order' => 0, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deal = DB::table('crm_opportunities')->insertGetId([
        'business_id' => $this->business->id, 'name' => 'Nazmul big order', 'lead_id' => $this->nazmulShop->id,
        'stage_id' => $stage, 'value' => 50000, 'status' => 'open', 'assigned_to_user_id' => $this->nazmul->id,
        'created_by' => $this->nazmul->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $board = ($this->as)($this->rakib)->get('/crm/pipeline')->assertOk()->viewData('page')['props']['stages'];

    expect(collect($board)->flatMap(fn ($s) => collect($s['opportunities'])->pluck('name'))->all())->toBe([]);

    ($this->as)($this->rakib)->post("/crm/opportunities/{$deal}/won")->assertNotFound();

    expect(DB::table('crm_opportunities')->where('id', $deal)->value('status'))->toBe('open');
});

it('puts an officer\'s own deal on his board', function () {
    $stage = DB::table('crm_pipeline_stages')->insertGetId([
        'business_id' => $this->business->id, 'name' => 'Qualification', 'sort_order' => 0, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->as)($this->rakib)->post('/crm/opportunities', [
        'name' => 'Rakib order', 'lead_id' => $this->rakibShop->id, 'stage_id' => $stage, 'value' => 1000,
    ])->assertSessionHasNoErrors();

    expect(DB::table('crm_opportunities')->where('name', 'Rakib order')->value('assigned_to_user_id'))->toBe($this->rakib->id);
});

it('counts only visible leads in the CRM reports', function () {
    app()->forgetScopedInstances();

    $funnel = ($this->as)($this->rakib)->get('/crm/reports')->assertOk()->viewData('page')['props']['funnel'];

    expect($funnel['workable'])->toBe(1);
});

it('will not pull a lead into the phone book for somebody who cannot see it', function () {
    // ⚠⚠ The phone book is readable by Staff. Pulling every lead into it would
    // be a back door around the whole rule.
    Permission::firstOrCreate(['name' => 'crm.contacts.manage', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Sales Officer')->sole()
        ->givePermissionTo('crm.contacts.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->as)($this->rakib)->post('/crm/contacts/pull', ['type' => 'lead'])->assertRedirect();

    expect(Contact::pluck('name')->all())->toBe(['Rakib Store']);
});

it('shows follow-ups in the queue and the calendar by the same rule', function () {
    foreach ([[$this->rakib, $this->rakibShop], [$this->nazmul, $this->nazmulShop], [$this->stranger, $this->strangerShop]] as [$who, $lead]) {
        CrmTask::create([
            'business_id' => $this->business->id, 'subject_type' => Lead::class, 'subject_id' => $lead->id,
            'title' => "Call {$lead->name}", 'due_at' => '2026-09-16 10:00:00',
            'assigned_to_user_id' => $who->id, 'status' => TaskStatus::Open, 'created_by' => $who->id,
        ]);
    }

    $queue = function (User $user): array {
        app()->forgetScopedInstances();

        return collect(($this->as)($user)->get('/crm/queue')->assertOk()->viewData('page')['props']['tasks']['data'])
            ->pluck('title')->sort()->values()->all();
    };

    expect($queue($this->rakib))->toBe(['Call Rakib Store'])
        // ⭐ The ASM now sees his officers' follow-ups going overdue.
        ->and($queue($this->asm))->toBe(['Call Nazmul Store', 'Call Rakib Store']);

    // ⚠⚠ The calendar showed every follow-up in the business before this.
    app()->forgetScopedInstances();
    $this->actingAs($this->rakib);
    $calendar = collect(app(CalendarFeed::class)->between($this->rakib, '2026-09-01', '2026-09-30'))
        ->where('type', 'follow_up')->pluck('title')->values()->all();

    expect($calendar)->toBe(['Call Rakib Store']);
});

/* -------------------------------------------------------------------------
 | Duplicates: warned by the holder's name, and nothing more (D4)
 | ---------------------------------------------------------------------- */

it('warns about a colleague\'s lead by its holder only', function () {
    app()->forgetScopedInstances();

    $response = ($this->as)($this->rakib)->getJson('/crm/leads/duplicates?phone=01711000002')->assertOk();

    expect($response->json('leads'))->toBe([])
        ->and($response->json('held_elsewhere'))->toBe([['held_by' => 'Nazmul']])
        ->and($response->getContent())->not->toContain('Nazmul Store');
});

it('refuses a second shop on a colleague\'s number without naming the shop', function () {
    ($this->as)($this->rakib)->post('/crm/leads', [
        'name' => 'Copy Store', 'phone' => '01711000002', 'lead_type' => 'retailer', 'status' => 'new', 'district' => 'Dhaka', 'thana' => 'Mirpur',
    ])->assertSessionHasErrors(['phone' => 'This number already belongs to a lead held by Nazmul.']);

    expect(Lead::withoutGlobalScopes()->where('name', 'Copy Store')->exists())->toBeFalse();
});

/* -------------------------------------------------------------------------
 | Files on a lead
 | ---------------------------------------------------------------------- */

it('keeps a colleague\'s lead files out of the file manager and the stream', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/shop.jpg', 'photo-bytes');

    $file = Attachment::create([
        'business_id' => $this->business->id, 'attachable_type' => Lead::class, 'attachable_id' => $this->nazmulShop->id,
        'collection' => 'photos', 'disk' => 'local', 'path' => 'attachments/shop.jpg',
        'original_name' => 'shop.jpg', 'mime_type' => 'image/jpeg', 'size' => 11, 'uploaded_by' => $this->nazmul->id,
    ]);

    Permission::firstOrCreate(['name' => 'files.view', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Sales Officer')->sole()->givePermissionTo('files.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $listed = function (User $user): array {
        app()->forgetScopedInstances();

        return collect(($this->as)($user)->get('/files')->assertOk()->viewData('page')['props']['files']['data'])
            ->pluck('filename')->all();
    };

    expect($listed($this->rakib))->toBe([])
        ->and($listed($this->nazmul))->toBe(['shop.jpg']);

    // ⚠⚠ Before this slice a hidden owner came back null and the check was
    // skipped entirely - the file streamed.
    app()->forgetScopedInstances();
    ($this->as)($this->rakib)->get(route('attachments.show', $file))->assertNotFound();

    app()->forgetScopedInstances();
    ($this->as)($this->nazmul)->get(route('attachments.show', $file))->assertOk();
});

// ⚠ Bug hunt 16 Oct 2026 (03 C4): a lead handed to another officer left its open
// follow-ups with the old one, who could not open them (404) and was still nagged.
it('moves the old officer open follow-ups with a reassigned lead, and leaves the rest', function () {
    $task = fn (User $to, string $status = 'open') => CrmTask::create([
        'business_id' => $this->business->id, 'subject_type' => Lead::class, 'subject_id' => $this->rakibShop->id,
        'title' => 'Call Rakib Store', 'due_at' => '2026-09-16 10:00:00',
        'assigned_to_user_id' => $to->id, 'status' => $status, 'created_by' => $to->id,
    ]);
    $open = $task($this->rakib);
    $done = $task($this->rakib, 'done');
    $managers = $task($this->asm);

    ($this->as)($this->owner)->put("/crm/leads/{$this->rakibShop->id}", [
        'lead_type' => 'retailer', 'name' => 'Rakib Store', 'phone' => '01711000001', 'status' => 'new',
        'district' => 'Dhaka', 'thana' => 'Mirpur', 'assigned_to_user_id' => $this->nazmul->id,
    ])->assertSessionHasNoErrors();

    expect($this->rakibShop->fresh()->assigned_to_user_id)->toBe($this->nazmul->id)
        ->and($open->fresh()->assigned_to_user_id)->toBe($this->nazmul->id)
        ->and($done->fresh()->assigned_to_user_id)->toBe($this->rakib->id)
        ->and($managers->fresh()->assigned_to_user_id)->toBe($this->asm->id);
});

// ⚠ Bug hunt 16 Oct 2026 (03 C5, decided 17 Oct): a contact pulled from a lead
// follows the lead list's own visibility rule, in the phone book and its export.
it('shows a lead contact in the phone book only to those who may see the lead', function () {
    Permission::findOrCreate('crm.contacts.view', 'web');
    $this->rakib->givePermissionTo('crm.contacts.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $contact = fn (string $name, string $phone, ?Lead $lead) => Contact::create([
        'business_id' => $this->business->id, 'name' => $name, 'phone' => $phone,
        'linked_type' => $lead === null ? null : Lead::class, 'linked_id' => $lead?->id,
    ]);
    $contact('Rakib Store', '01711000001', $this->rakibShop);
    $contact('Nazmul Store', '01711000002', $this->nazmulShop);
    $contact('Plain Dealer', '01711000009', null);

    $names = collect(($this->as)($this->rakib)->get('/crm/contacts')->assertOk()->original->getData()['page']['props']['contacts']['data'])->pluck('name')->sort()->values()->all();
    $csv = ($this->as)($this->rakib)->get('/crm/contacts/export?format=csv')->assertOk()->streamedContent();

    expect($names)->toBe(['Plain Dealer', 'Rakib Store'])
        ->and($csv)->toContain('Rakib Store')->not->toContain('Nazmul Store');

    // Somebody who may see every lead sees every contact.
    $all = collect(($this->as)($this->owner)->get('/crm/contacts')->original->getData()['page']['props']['contacts']['data'])->pluck('name');
    expect($all)->toHaveCount(3);
});
