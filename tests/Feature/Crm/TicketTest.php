<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4, Slice 4 (CRM spec §20-§21): customer service tickets, phase 1.
 *
 * Phone and counter complaints, honestly scoped: a TK-numbered record with
 * category, priority, and a RESOLVE-BY stamped at creation from the
 * priority's SLA hours - stamped, so changing the rule later never rewrites
 * history. Resolving requires saying what was done; a breach is computed,
 * never typed. No email or portal machinery is pretended.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ticket Co', 'slug' => 'ticket-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 10:00:00');

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Traders', 'type' => 'dealer', 'is_active' => true,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function makeTicket(array $overrides = []): int
{
    (test()->asOwner)()->post(route('crm.tickets.store'), array_merge([
        'subject' => 'Broken jar in the carton',
        'description' => 'Two jars arrived cracked in yesterday delivery.',
        'customer_id' => test()->customer->id,
        'category' => 'quality',
        'priority' => 'high',
    ], $overrides))->assertSessionHasNoErrors();

    return (int) DB::table('crm_tickets')->latest('id')->value('id');
}

it('stamps the resolve-by from the priority when the ticket is born', function () {
    makeTicket();

    $ticket = DB::table('crm_tickets')->first();
    expect($ticket->ticket_number)->toStartWith('TK-')
        ->and($ticket->status)->toBe('open')
        // High priority: 24 hours to resolve, stamped at creation.
        ->and(Carbon::parse($ticket->resolve_by)->toDateTimeString())->toBe('2026-09-04 10:00:00');

    ($this->asOwner)()->get(route('crm.tickets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/tickets')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.party', 'Rahim Traders')
            ->where('tickets.data.0.breached', false));
});

it('takes a walk-in complaint with just a name and phone', function () {
    makeTicket(['customer_id' => null, 'caller_name' => 'Karim', 'caller_phone' => '01766666666', 'priority' => 'normal']);

    $ticket = DB::table('crm_tickets')->first();
    // Normal priority: 72 hours.
    expect(Carbon::parse($ticket->resolve_by)->toDateTimeString())->toBe('2026-09-06 10:00:00')
        ->and($ticket->caller_name)->toBe('Karim');
});

it('refuses a ticket that belongs to nobody at all', function () {
    ($this->asOwner)()->post(route('crm.tickets.store'), [
        'subject' => 'Orphan complaint', 'category' => 'other', 'priority' => 'low',
    ])->assertSessionHasErrors();
});

it('keeps replies and internal notes, and resolving requires the resolution', function () {
    $ticket = makeTicket();

    ($this->asOwner)()->post(route('crm.tickets.reply', $ticket), [
        'body' => 'Called the dealer, replacement goes with tomorrow delivery.',
        'is_internal' => false,
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->post(route('crm.tickets.resolve', $ticket), [])
        ->assertSessionHasErrors('resolution');

    ($this->asOwner)()->post(route('crm.tickets.resolve', $ticket), [
        'resolution' => 'Two replacement jars delivered and confirmed.',
    ])->assertSessionHasNoErrors();

    $resolved = DB::table('crm_tickets')->find($ticket);
    expect($resolved->status)->toBe('resolved')
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and(DB::table('crm_ticket_replies')->where('ticket_id', $ticket)->count())->toBe(1);
});

it('computes a breach - resolved late is breached, never typed', function () {
    $ticket = makeTicket(); // high: resolve_by = tomorrow 10:00

    Carbon::setTestNow('2026-09-05 10:00:00'); // two days later

    ($this->asOwner)()->post(route('crm.tickets.resolve', $ticket), [
        'resolution' => 'Sorted, late.',
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('crm.tickets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tickets.data.0.breached', true));

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('crm.tickets'))
        ->assertForbidden();
});

// CRM-009 (audit 14 Oct 2026): a ticket goes to a member of this business.
it('refuses to give a ticket to a login from another business, on opening or on reassigning', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    app(BusinessContext::class)->set($this->business->id);

    ($this->asOwner)()->post(route('crm.tickets.store'), [
        'subject' => 'Late delivery', 'customer_id' => $this->customer->id,
        'category' => 'delivery', 'priority' => 'normal', 'assigned_to_user_id' => $stranger->id,
    ])->assertSessionHasErrors('assigned_to_user_id');

    $id = makeTicket();

    ($this->asOwner)()->post(route('crm.tickets.assign', $id), ['assigned_to_user_id' => $stranger->id])
        ->assertSessionHasErrors('assigned_to_user_id');

    expect(DB::table('crm_tickets')->where('id', $id)->value('assigned_to_user_id'))->toBeNull();
});

// CRM-010 (audit 14 Oct 2026): the list loaded replies one ticket at a time and
// each reply's author one reply at a time.
it('loads the ticket list in the same number of queries however many replies there are', function () {
    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        ($this->asOwner)()->get(route('crm.tickets'))->assertOk();

        return $queries;
    };

    $first = makeTicket();
    ($this->asOwner)()->post(route('crm.tickets.reply', $first), ['body' => 'Called the customer.']);
    $few = $count();

    foreach (range(1, 4) as $i) {
        $id = makeTicket(['subject' => "Ticket {$i}"]);
        foreach (range(1, 3) as $j) {
            ($this->asOwner)()->post(route('crm.tickets.reply', $id), ['body' => "Reply {$j}"]);
        }
    }
    $many = $count();

    // No more than the one-reply page (the first visit also warms a lookup or two).
    expect($many)->toBeLessThanOrEqual($few);

    ($this->asOwner)()->get(route('crm.tickets'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 5)
            ->where('tickets.data.0.replies.0.by', $this->owner->name));
});

// CRM-011 (audit 14 Oct 2026): the reassign route had no caller on the screen.
it('lets a ticket be handed to somebody else from its card', function () {
    $colleague = User::factory()->create(['name' => 'Karim']);
    $colleague->businesses()->attach($this->business->id);
    $id = makeTicket();

    ($this->asOwner)()->post(route('crm.tickets.assign', $id), ['assigned_to_user_id' => $colleague->id])
        ->assertSessionHasNoErrors();
    expect((int) DB::table('crm_tickets')->where('id', $id)->value('assigned_to_user_id'))->toBe($colleague->id);

    $page = file_get_contents(resource_path('js/pages/crm/tickets.tsx'));
    expect($page)->toContain('/crm/tickets/${ticket.id}/assign')
        ->and($page)->toContain('<TicketCard key={ticket.id} ticket={ticket} users={users} />');
});
