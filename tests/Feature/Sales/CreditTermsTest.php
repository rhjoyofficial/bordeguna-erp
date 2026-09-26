<?php

use App\Models\User;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\ApproveCreditTermsRequest;
use App\Modules\Sales\Actions\PlanCreditTermsRequest;
use App\Modules\Sales\Actions\SubmitCreditTermsRequest;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CreditTermsCsv;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Credit limits and days come as a file and take effect only on the
 * authority's yes (Joy, 26 Sep 2026, Q16).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Terms Co', 'slug' => 'terms-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    (new ApprovalWorkflowSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->as = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->dealer = Customer::create(['business_id' => $this->business->id, 'code' => 'DLR-001', 'name' => 'Rahim Traders', 'type' => CustomerType::Dealer, 'credit_limit' => '50000', 'credit_days' => 15]);
    $this->shop = Customer::create(['business_id' => $this->business->id, 'code' => 'SHP-001', 'name' => 'Corner Store', 'type' => CustomerType::Shop]);
    $this->person = Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-001', 'name' => 'A Person', 'type' => CustomerType::Retail]);

    $this->rows = fn (string $body) => CreditTermsCsv::readHandle((function () use ($body) {
        $h = fopen('php://temp', 'r+');
        fwrite($h, implode(',', CreditTermsCsv::COLUMNS)."\n".$body);
        rewind($h);

        return $h;
    })());
});

it('plans the file against the customers as they are: changes, unchanged rows and refusals', function () {
    $plan = app(PlanCreditTermsRequest::class)->handle(($this->rows)(
        "DLR-001,Rahim Traders,Dealer,50000,15,80000,30\n".
        "SHP-001,Corner Store,Retail shop,,,20000,\n".
        "CUS-001,A Person,Retail customer,,,5000,10\n".
        "NOPE-1,Nobody,,,,1000,\n".
        "DLR-001,Rahim Traders,Dealer,50000,15,1,1\n".
        "SHP-001,Corner Store,Retail shop,,,abc,\n"
    ));

    expect(collect($plan['changes'])->pluck('code')->all())->toBe(['DLR-001', 'SHP-001'])
        ->and($plan['changes'][0]['new_limit'])->toBe('80000.0000')
        ->and($plan['changes'][0]['new_days'])->toBe(30)
        ->and($plan['changes'][1]['new_days'])->toBeNull()
        ->and(collect($plan['errors'])->pluck('message')->implode(' '))->toContain('Retail customer', 'No customer has the code NOPE-1', 'appears twice');

    // A row that repeats the current figures changes nothing.
    $same = app(PlanCreditTermsRequest::class)->handle(($this->rows)("DLR-001,Rahim Traders,Dealer,50000,15,50000,15\n"));
    expect($same['changes'])->toBe([])->and($same['unchanged'])->toHaveCount(1);
});

it('sends the changes for approval and changes no customer until the authority approves', function () {
    $manager = ($this->as)('Manager');
    $owner = ($this->as)('Owner');

    $request = app(SubmitCreditTermsRequest::class)->handle(($this->rows)("DLR-001,,,,,80000,30\nSHP-001,,,,,20000,\n"), $manager, 'agreed on the visit', 'terms.csv');

    expect($request->request_number)->toBe('CT-0001')
        ->and($request->line_count)->toBe(2)
        ->and($request->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($request->approvalRequest->workflow_id)->not->toBeNull()
        ->and($this->dealer->fresh()->credit_limit)->toBe('50000.0000')
        ->and($this->shop->fresh()->credit_limit)->toBeNull();

    // The inbox shows it to the Owner (sales.credit.approve) and not to the Manager.
    expect(app(ApprovalInbox::class)->pendingActionableFor($owner)->pluck('approvable_id')->all())->toContain($request->id)
        ->and(app(ApprovalInbox::class)->pendingActionableFor($manager)->pluck('approvable_id')->all())->not->toContain($request->id);

    $card = app(ApprovalCards::class)->present($request->approvalRequest->fresh(['approvable.lines.customer', 'approvable.requestedBy']));
    expect($card['type'])->toBe('Credit terms')
        ->and($card['summary'])->toContain('CT-0001', '2 customer(s)')
        ->and($card['view_url'])->toContain('/sales/credit-terms/'.$request->id);

    // A Manager cannot approve; the Owner's approval applies every line at once.
    expect(fn () => app(ApproveCreditTermsRequest::class)->handle($request, $manager))->toThrow(RuntimeException::class);

    $applied = app(ApproveCreditTermsRequest::class)->handle($request, $owner, 'ok');

    expect($applied->isApplied())->toBeTrue()
        ->and($this->dealer->fresh()->credit_limit)->toBe('80000.0000')
        ->and($this->dealer->fresh()->credit_days)->toBe(30)
        ->and($this->shop->fresh()->credit_limit)->toBe('20000.0000')
        ->and($this->shop->fresh()->credit_days)->toBeNull()
        ->and($applied->lines->first()->applied_over_credit_limit)->toBe('50000.0000');

    // Twice is refused.
    expect(fn () => app(ApproveCreditTermsRequest::class)->handle($applied, $owner))->toThrow(RuntimeException::class);
});

it('refuses a file with an error or with nothing to change, and numbers requests per business', function () {
    $manager = ($this->as)('Manager');

    expect(fn () => app(SubmitCreditTermsRequest::class)->handle(($this->rows)("NOPE-1,,,,,1000,\n"), $manager))->toThrow(ValidationException::class);
    expect(fn () => app(SubmitCreditTermsRequest::class)->handle(($this->rows)("DLR-001,,,,,50000,15\n"), $manager))->toThrow(ValidationException::class);

    app(SubmitCreditTermsRequest::class)->handle(($this->rows)("DLR-001,,,,,60000,\n"), $manager);
    $second = app(SubmitCreditTermsRequest::class)->handle(($this->rows)("DLR-001,,,,,70000,\n"), $manager);

    expect($second->request_number)->toBe('CT-0002')->and(CreditTermsRequest::count())->toBe(2);
});

it('walks the screens: template, review, submit, show, approve, reject', function () {
    $manager = ($this->as)('Manager');
    $owner = ($this->as)('Owner');

    $csv = $this->actingAs($manager)->get('/sales/credit-terms/template')->assertOk()->streamedContent();
    expect($csv)->toContain('code,name,type,current_limit,current_days,new_limit,new_days', 'DLR-001', 'SHP-001')
        ->and($csv)->not->toContain('CUS-001');

    $path = tempnam(sys_get_temp_dir(), 'ct').'.csv';
    file_put_contents($path, implode(',', CreditTermsCsv::COLUMNS)."\nDLR-001,Rahim Traders,Dealer,50000,15,90000,45\n");
    $file = new UploadedFile($path, 'terms.csv', 'text/csv', null, true);

    $this->actingAs($manager)->from('/sales/credit-terms')
        ->post('/sales/credit-terms/review', ['file' => $file])
        ->assertRedirect('/sales/credit-terms')
        ->assertSessionHas('creditTermsPlan', fn ($plan) => count($plan['changes']) === 1 && $plan['errors'] === [] && str_contains($plan['changes'][0]['limit'], '90,000.00'));

    $this->actingAs($manager)->from('/sales/credit-terms')
        ->post('/sales/credit-terms', ['note' => 'visit'])
        ->assertRedirect();

    $request = CreditTermsRequest::firstOrFail();
    expect($this->dealer->fresh()->credit_limit)->toBe('50000.0000');

    $page = $this->actingAs($owner)->get('/sales/credit-terms/'.$request->id)->assertOk()->viewData('page')['props'];
    expect($page['request']['lines'][0]['proposed_limit'])->toBe('90,000.00')
        ->and($page['request']['lines'][0]['drifted'])->toBeFalse()
        ->and($page['mayApprove'])->toBeTrue();

    // Staff may look but not approve; the Manager may not approve either.
    $this->actingAs($manager)->post('/sales/credit-terms/'.$request->id.'/approve')->assertForbidden();

    $this->actingAs($owner)->from('/sales/credit-terms/'.$request->id)
        ->post('/sales/credit-terms/'.$request->id.'/approve', ['note' => 'fine'])
        ->assertSessionHas('success');
    expect($this->dealer->fresh()->credit_limit)->toBe('90000.0000')->and($this->dealer->fresh()->credit_days)->toBe(45);

    // A second request, rejected: nothing moves.
    $rejected = app(SubmitCreditTermsRequest::class)->handle(($this->rows)("DLR-001,,,,,1,1\n"), $manager);
    $this->actingAs($owner)->from('/sales/credit-terms/'.$rejected->id)
        ->post('/sales/credit-terms/'.$rejected->id.'/reject', ['review_note' => 'no'])
        ->assertSessionHas('success');
    expect($rejected->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Rejected)
        ->and($this->dealer->fresh()->credit_limit)->toBe('90000.0000');

    $this->actingAs($manager)->get('/sales/credit-terms')->assertOk();
});

it('keeps credit limit and days away from the customer form and the plain import unless the person is the authority', function () {
    $manager = ($this->as)('Manager');
    $owner = ($this->as)('Owner');

    // A Manager's typed limit is ignored; the Owner's is kept.
    $this->actingAs($manager)->post('/sales/customers', ['name' => 'By Manager', 'type' => 'dealer', 'credit_limit' => '99999', 'credit_days' => '99'])->assertSessionHasNoErrors();
    $byManager = Customer::where('name', 'By Manager')->firstOrFail();
    expect($byManager->credit_limit)->toBeNull()->and($byManager->credit_days)->toBeNull();

    $this->actingAs($owner)->post('/sales/customers', ['name' => 'By Owner', 'type' => 'dealer', 'credit_limit' => '99999', 'credit_days' => '99'])->assertSessionHasNoErrors();
    $byOwner = Customer::where('name', 'By Owner')->firstOrFail();
    expect($byOwner->credit_limit)->toBe('99999.0000')->and($byOwner->credit_days)->toBe(99);

    // Editing as a Manager keeps the Owner's figures.
    $this->actingAs($manager)->put('/sales/customers/'.$byOwner->id, ['name' => 'By Owner', 'type' => 'dealer', 'credit_limit' => '1', 'credit_days' => '1'])->assertSessionHasNoErrors();
    expect($byOwner->fresh()->credit_limit)->toBe('99999.0000')->and($byOwner->fresh()->credit_days)->toBe(99);

    $page = $this->actingAs($manager)->get('/sales/customers')->assertOk()->viewData('page')['props'];
    expect($page['mayGrantCredit'])->toBeFalse();
});
