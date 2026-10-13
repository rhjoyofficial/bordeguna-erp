<?php

use App\Models\User;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register slice 4: the accounts import. Template, preview, apply;
 * one error and nothing is written; an account is matched on kind +
 * identifier, else kind + name; employees by code or exact full name.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    // ⚠ New pages are not in the committed build manifest; the screens are proved by props, not by Vite.
    $this->withoutVite();
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->custodian = ($this->user)('IT Custodian');
    $this->manager = ($this->user)('Manager');
    $this->owner = ($this->user)('Owner');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);

    $this->employee = function (string $first, string $code): Employee {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01',
        ]);
        EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $employee->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);

        return $employee;
    };

    $this->moin = ($this->employee)('Moin', 'BDG-000001');
    $this->joy = ($this->employee)('Joy', 'BDG-000016');
    $this->shawon = ($this->employee)('Shawon', 'BDG-000012');

    $this->header = 'kind,name,provider,identifier,url,registered_as,responsible,users,two_factor_holder,two_factor_method,renews_on,renewal_cycle,renewal_cost,status,recovery_email,recovery_phone,shared_with,note';
    $this->csv = fn (array $lines) => UploadedFile::fake()->createWithContent('accounts.csv', $this->header."\n".implode("\n", $lines)."\n");
    $this->preview = fn (User $as, UploadedFile $file) => $this->actingAs($as)->post('/assets/accounts/import/preview', ['file' => $file]);
    $this->props = fn ($response) => $response->assertOk()->original->getData()['page']['props']['preview'];
});

afterEach(fn () => Carbon::setTestNow());

it('serves the template and gates the importer on managing the hub', function () {
    $this->actingAs($this->manager)->get('/assets/accounts/import')->assertForbidden();
    $this->actingAs($this->manager)->get('/assets/accounts/import/template')->assertForbidden();
    $this->actingAs($this->manager)->post('/assets/accounts/import/preview', ['file' => ($this->csv)([])])->assertForbidden();

    $this->actingAs($this->custodian)->get('/assets/accounts/import')->assertOk();
    $template = $this->actingAs($this->custodian)->get('/assets/accounts/import/template')->assertOk()->streamedContent();
    expect(str_replace("\xEF\xBB\xBF", '', explode("\n", $template)[0]))->toBe($this->header)
        ->and($template)->not->toContain('password');
    // A refreshed preview lands on the import page.
    $this->actingAs($this->custodian)->get('/assets/accounts/import/preview')->assertRedirect('/assets/accounts/import');
});

it('previews every error and writes nothing until the file is clean', function () {
    // kind,name,provider,identifier,url,registered_as,responsible,users,two_factor_holder,two_factor_method,renews_on,renewal_cycle,renewal_cost,status,recovery_email,recovery_phone,shared_with,note
    $bad = ($this->csv)([
        'fax,Old fax,,,,,,,,,,,,,,,,',
        'domain,laladia.com,Nikolahost,laladia.com,,,BDG-999999,,,,2027-02-01,,,active,,,,',
        'email,Support mailbox,Hostinger,support@laladia.com,,,BDG-000016,"BDG-000012;Nobody Here",,,,,,active,,,,',
        'saas,Pathao merchant,Pathao,393838,,,Joy Test,,,pigeon,2027-13-40,weekly,abc,paused,not-an-email,,nowhere,',
        'domain,laladia.com,Nikolahost,LALADIA.COM,,,,,,,,,,,,,,',
        'email,,,,,,,,,,,,,,,,,',
    ]);

    $preview = ($this->props)(($this->preview)($this->owner, $bad));
    $messages = collect($preview['errors'])->map(fn ($e) => $e['line'].': '.$e['message'])->all();

    expect($preview['creates'])->toBe([])
        ->and($preview['updates'])->toBe([])
        ->and($messages[0])->toStartWith('2: kind "fax" is not one of: email, domain')
        ->and($messages[1])->toContain('3: responsible "BDG-999999" is not an employee of this business')
        ->and($messages[2])->toContain('4: users "Nobody Here" is not an employee')
        ->and($messages[3])->toContain('renews_on "2027-13-40" must be a date')
        ->and($messages[3])->toContain('renewal_cost "abc" must be a number')
        ->and($messages[3])->toContain('status "paused" must be active, suspended or closed')
        ->and($messages[3])->toContain('two_factor_method "pigeon" must be one of')
        ->and($messages[3])->toContain('renewal_cycle "weekly" must be one of')
        ->and($messages[3])->toContain('shared_with "nowhere" is not another business of the group')
        ->and($messages[3])->toContain('recovery_email "not-an-email" is not an email address')
        ->and($messages[4])->toContain('6: The same account is already on line 3')
        ->and($messages[5])->toContain('7: The row has no name')
        ->and(CompanyAccount::query()->count())->toBe(0);

    // Apply with that file is refused and writes nothing.
    $this->actingAs($this->owner)->post('/assets/accounts/import', ['token' => $preview['token'], 'hash' => $preview['hash']])->assertSessionHasErrors('file');
    expect(CompanyAccount::query()->count())->toBe(0);

    // A cost from someone without costs.view is a row error, never a silently dropped column (review ruling 7).
    $costly = ($this->csv)(['domain,laladia.com,Nikolahost,laladia.com,,,BDG-000016,,,,2027-02-01,yearly,1800,active,,,,']);
    $refused = ($this->props)(($this->preview)($this->custodian, $costly));
    expect($refused['errors'])->toHaveCount(1)
        ->and($refused['errors'][0]['message'])->toContain('renewal_cost is not yours to set');
    expect(($this->props)(($this->preview)($this->owner, $costly))['errors'])->toBe([]);

    // A wrong header is one message, not hundreds.
    $wrong = UploadedFile::fake()->createWithContent('accounts.csv', "kind,name,password\nemail,x,secret\n");
    ($this->preview)($this->custodian, $wrong)->assertSessionHasErrors('file');
});

it('applies a clean file: creates, updates by kind + identifier or kind + name, and adds access rows', function () {
    // An account already here, with Moin's access, to be matched and updated.
    $existing = CompanyAccount::create(['kind' => 'social_page', 'name' => 'LaLaDia Facebook page', 'provider' => 'Meta', 'identifier' => '102194475966992', 'created_by' => $this->custodian->id]);
    $existing->access()->create(['employee_id' => $this->moin->id, 'role' => 'admin', 'granted_on' => '2026-01-01', 'granted_by' => $this->custodian->id]);
    $byName = CompanyAccount::create(['kind' => 'bank_portal', 'name' => 'BRAC Bank internet banking', 'provider' => 'BRAC', 'created_by' => $this->custodian->id]);

    // kind,name,provider,identifier,url,registered_as,responsible,users,two_factor_holder,two_factor_method,renews_on,renewal_cycle,renewal_cost,status,recovery_email,recovery_phone,shared_with,note
    $good = ($this->csv)([
        'email,Support mailbox,Hostinger,support@laladia.com,https://mail.hostinger.com,joy@gmail.com,BDG-000016,"BDG-000012;BDG-000016",BDG-000016,sms,,none,,active,,,,Shared by the CS team',
        'domain,laladia.com,Nikolahost,laladia.com,https://asiacp2000.nikolahost.com,,joy test,,,,2027-02-01,yearly,1800,,,,,Renews yearly',
        'social_page,LaLaDia Facebook page,Meta,102194475966992,https://business.facebook.com,,BDG-000001,"BDG-000001;BDG-000016",BDG-000001,authenticator,,none,,active,,,,Inside the portfolio',
        'bank_portal,brac bank internet banking,BRAC Bank,2067683230001,https://www.bracbank.com,,BDG-000001,,BDG-000001,,,,,active,,,,Owner only',
    ]);

    $preview = ($this->props)(($this->preview)($this->owner, $good));
    expect($preview['errors'])->toBe([])
        ->and(collect($preview['creates'])->pluck('name')->all())->toBe(['Support mailbox', 'laladia.com'])
        ->and(collect($preview['updates'])->pluck('name')->all())->toBe(['LaLaDia Facebook page', 'brac bank internet banking'])
        ->and($preview['updates'][0]['new_users'])->toBe(['BDG-000016'])
        ->and(collect($preview['updates'][0]['changes'])->pluck('field')->all())->toContain('url', 'responsible_employee_id', 'two_factor_method', 'two_factor_employee_id', 'note')
        ->and($preview['creates'][0]['users'])->toBe(2);

    $this->actingAs($this->owner)->post('/assets/accounts/import', ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasNoErrors()->assertRedirect('/assets/accounts');

    expect(CompanyAccount::query()->count())->toBe(4);

    $mailbox = CompanyAccount::query()->where('name', 'Support mailbox')->sole();
    expect($mailbox->responsible_employee_id)->toBe($this->joy->id)
        ->and($mailbox->registered_as)->toBe('joy@gmail.com')
        ->and($mailbox->two_factor_method->value)->toBe('sms')
        ->and($mailbox->two_factor_employee_id)->toBe($this->joy->id)
        ->and($mailbox->activeAccess()->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->shawon->id, $this->joy->id])->sort()->values()->all())
        ->and(CompanyAccountAccess::query()->where('company_account_id', $mailbox->id)->first()->role->value)->toBe('user');

    $domain = CompanyAccount::query()->where('name', 'laladia.com')->sole();
    expect($domain->responsible_employee_id)->toBe($this->joy->id)
        ->and($domain->renewal_cycle->value)->toBe('yearly')
        ->and($domain->renews_on->toDateString())->toBe('2027-02-01')
        ->and((string) $domain->renewal_cost)->toBe('1800.0000')
        ->and($domain->status->value)->toBe('active');

    // Matched by identifier: updated, Moin's access kept, Joy's added.
    $page = $existing->fresh();
    expect($page->url)->toBe('https://business.facebook.com')
        ->and($page->responsible_employee_id)->toBe($this->moin->id)
        ->and($page->activeAccess()->count())->toBe(2)
        ->and($page->activeAccess()->where('employee_id', $this->moin->id)->first()->role->value)->toBe('admin');

    // Matched by name (no identifier before): the identifier is filled in.
    expect($byName->fresh()->identifier)->toBe('2067683230001')->and($byName->fresh()->name)->toBe('brac bank internet banking');

    // The same file again changes nothing.
    $again = ($this->props)(($this->preview)($this->owner, $good));
    expect($again['creates'])->toBe([])->and($again['updates'])->toBe([])->and($again['unchanged'])->toBe(4);

    // Round trip: the export imports as unchanged, for the Owner (with costs) and the custodian (without) alike.
    $csv = $this->actingAs($this->owner)->get('/assets/accounts/export')->streamedContent();
    $round = ($this->props)(($this->preview)($this->owner, UploadedFile::fake()->createWithContent('export.csv', $csv)));
    expect($round['errors'])->toBe([])->and($round['unchanged'])->toBe(4);
    $blank = $this->actingAs($this->custodian)->get('/assets/accounts/export')->streamedContent();
    $roundBlank = ($this->props)(($this->preview)($this->custodian, UploadedFile::fake()->createWithContent('export.csv', $blank)));
    expect($roundBlank['errors'])->toBe([])->and($roundBlank['unchanged'])->toBe(4);
});
