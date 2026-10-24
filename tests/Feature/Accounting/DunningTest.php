<?php

use App\Models\User;
use App\Modules\Accounting\Mail\PaymentReminderMail;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 5 (spec §5 collections): payment reminders.
 *
 * ⚠⚠ NOTHING SENDS ITSELF. A reminder email goes to a CUSTOMER, outside the
 * building - each one is a human's click, logged with who sent it and what
 * it covered. The screen reads the SAME Ageing engine as the receivables
 * report, so the two can never disagree about who owes what.
 */
beforeEach(function () {
    // Hidden since 24 Oct 2026 (Joy); these tests prove the feature still works when switched back on.
    config(['features.dunning' => true]);

    $this->business = Business::create(['name' => 'Dun Co', 'slug' => 'dun-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales.receipt.record', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Collector', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'sales.receipt.record']);
    Role::firstOrCreate(['name' => 'Watcher', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->collector = User::factory()->create();
    $this->collector->businesses()->attach($this->business->id);
    $this->collector->assignRole('Collector');

    $this->watcher = User::factory()->create();
    $this->watcher->businesses()->attach($this->business->id);
    $this->watcher->assignRole('Watcher');

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Store', 'type' => 'retail',
        'email' => 'rahim@example.com',
    ]);

    $this->makeInvoice = function (Customer $customer, string $number, float $total, string $due) {
        return DB::table('sales_invoices')->insertGetId([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'invoice_date' => date('Y-m-d', strtotime($due.' -15 days')),
            'due_date' => $due,
            'subtotal' => $total, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => $total,
            'created_by' => $this->collector->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

function asCollector()
{
    return test()->actingAs(test()->collector)->withSession(['current_business_id' => test()->business->id]);
}

it('shows who is overdue, from the same ageing engine as the report', function () {
    ($this->makeInvoice)($this->customer, 'INV-0001', 8500, now()->subDays(20)->toDateString());
    ($this->makeInvoice)($this->customer, 'INV-0002', 4000, now()->addDays(10)->toDateString()); // not due yet

    asCollector()
        ->get(route('accounting.dunning'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/dunning')
            ->has('customers', 1)
            ->where('customers.0.name', 'Rahim Store')
            ->where('customers.0.overdue_total', 8500)
            ->has('customers.0.invoices', 1)
            ->where('can_send', true));
});

it('sends one reminder as one logged human act', function () {
    Mail::fake();
    ($this->makeInvoice)($this->customer, 'INV-0001', 8500, now()->subDays(20)->toDateString());

    asCollector()
        ->post(route('accounting.dunning.remind', $this->customer))
        ->assertSessionHasNoErrors();

    Mail::assertQueued(PaymentReminderMail::class, fn ($mail) => $mail->hasTo('rahim@example.com'));

    $log = DB::table('payment_reminders')->first();
    expect($log->customer_id)->toBe($this->customer->id)
        ->and((float) $log->total_amount)->toBe(8500.0)
        ->and($log->sent_by)->toBe($this->collector->id);
});

it('refuses to remind a customer with no email address', function () {
    Mail::fake();
    $silent = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0002', 'name' => 'No Mail Traders', 'type' => 'retail',
    ]);
    ($this->makeInvoice)($silent, 'INV-0009', 900, now()->subDays(5)->toDateString());

    asCollector()
        ->post(route('accounting.dunning.remind', $silent))
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
});

it('never lets a viewer send, and never reminds somebody who owes nothing', function () {
    Mail::fake();

    test()->actingAs(test()->watcher)->withSession(['current_business_id' => test()->business->id])
        ->post(route('accounting.dunning.remind', $this->customer))
        ->assertForbidden();

    // Nothing overdue: the send is refused rather than sending an empty letter.
    asCollector()
        ->post(route('accounting.dunning.remind', $this->customer))
        ->assertSessionHasErrors('customer');

    Mail::assertNothingQueued();
});
