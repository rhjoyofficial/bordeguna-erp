<?php

use App\Models\User;
use App\Modules\Foundation\Mail\MorningBriefingMail;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 7, Slice 2: the morning briefing.
 *
 * One email at 07:00 to the people who read money: yesterday's sales, cash
 * now, standing costs due this week, and how many tickets are past their
 * clock. Figures come from the same arithmetic the screens use; the mail
 * invents nothing and is skipped entirely for a recipient-less business.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Brief Co', 'slug' => 'brief-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('accounting.view');

    Carbon::setTestNow('2026-09-10 07:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    // Yesterday: one 5,000 invoice.
    $customer = DB::table('customers')->insertGetId([
        'business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Rahim', 'type' => 'retail',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_invoices')->insert([
        'business_id' => $this->business->id, 'customer_id' => $customer,
        'invoice_number' => 'INV-1', 'invoice_date' => '2026-09-09',
        'subtotal' => 5000, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 5000,
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

it('mails the money-readers yesterday and today, with real figures', function () {
    $this->artisan('erp:morning-briefing')->assertExitCode(0);

    Mail::assertQueued(MorningBriefingMail::class, function (MorningBriefingMail $mail) {
        return $mail->hasTo($this->owner->email)
            && (float) $mail->figures['yesterday_sales'] === 5000.0;
    });
});

it('sends nothing when nobody can read the money', function () {
    $this->owner->removeRole('Owner');

    $this->artisan('erp:morning-briefing')->assertExitCode(0);

    Mail::assertNothingQueued();
});

// ⚠ Bug hunt 16 Oct 2026 (03 H1): deactivation keeps a user's roles, so a
// former accountant kept getting the money figures every morning.
it('never mails a deactivated user, or anybody about an inactive business', function () {
    $this->owner->forceFill(['is_active' => false])->save();

    $this->artisan('erp:morning-briefing')->assertExitCode(0);
    Mail::assertNothingQueued();

    $this->owner->forceFill(['is_active' => true])->save();
    $this->business->forceFill(['is_active' => false])->save();

    $this->artisan('erp:morning-briefing')->assertExitCode(0);
    Mail::assertNothingQueued();
});
