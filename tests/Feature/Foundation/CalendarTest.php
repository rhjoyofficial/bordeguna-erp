<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\CalendarEvent;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The business calendar.
 *
 * ⚠⚠ THE RISK THIS MODULE CARRIES IS NOT A WRONG DATE, IT IS A LEAK. A calendar
 * gathers from every module at once, so the easy mistake is to render everything
 * beautifully on a month grid and hand a warehouse hand every customer's due
 * date. Most of the tests below are about who is NOT shown a row.
 */
beforeEach(function () {
    // Hidden since 24 Oct 2026 (Joy); these tests prove the feature still works when switched back on.
    config(['features.calendar' => true, 'features.notes' => true]);

    $this->business = Business::create(['name' => 'Cal Co', 'slug' => 'cal-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['calendar.view', 'calendar.manage', 'sales.reports.view', 'purchasing.reports.view', 'crm.leads.view', 'hrm.leave.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['calendar.view', 'calendar.manage', 'sales.reports.view', 'purchasing.reports.view', 'crm.leads.view', 'hrm.leave.manage']);

    // ⚠ Holds the calendar and NOTHING else. This is the reader every leak test
    // below is about.
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['calendar.view', 'calendar.manage']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

/**
 * ⚠ Takes `mixed`, because assertInertia hands the closure a Collection rather
 * than the array the controller built. Typing this `array` made every test
 * using it fail on the helper instead of on the thing it was checking.
 */
function itemsOn(mixed $days, string $date): array
{
    foreach ($days as $day) {
        $day = (array) $day;

        if (($day['date'] ?? null) === $date) {
            return (array) ($day['items'] ?? []);
        }
    }

    return [];
}

it('draws a grid that starts before the month when the month does not start on a Saturday', function () {
    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('calendar/index')
            ->where('monthLabel', 'September 2026')
            // ⚠⚠ A whole number of weeks, always. Gathering only the month
            // itself leaves the leading and trailing cells silently empty while
            // the days beside them are full.
            ->where('days', fn ($days) => collect($days)->count() % 7 === 0 && collect($days)->count() >= 28)
        );
});

it('refuses a month it cannot parse instead of throwing a 500 at somebody editing the address bar', function () {
    $this->actingAs($this->owner)->get('/calendar?month=nonsense')->assertSessionHasErrors('month');
});

it('shows a holiday to everybody, because a closed office is not a secret', function () {
    $calendar = HolidayCalendar::create(['business_id' => $this->business->id, 'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5]]);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-10', 'name' => 'Public holiday']);

    foreach ([$this->owner, $this->staff] as $user) {
        $this->actingAs($user)->get('/calendar?month=2026-09')
            ->assertInertia(fn ($page) => $page->where('days', fn ($days) => collect(itemsOn($days, '2026-09-10'))
                ->contains(fn ($i) => $i['type'] === 'holiday' && $i['title'] === 'Public holiday')));
    }
});

it('never puts an invoice due date in front of somebody who may not open the invoice list', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Shop A']);
    SalesInvoice::create([
        'business_id' => $this->business->id, 'customer_id' => $customer->id, 'invoice_number' => 'INV-1',
        'invoice_date' => '2026-09-01', 'due_date' => '2026-09-15',
        'subtotal' => 100, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 100,
        'created_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => collect(itemsOn($days, '2026-09-15'))
            ->contains(fn ($i) => $i['type'] === 'invoice_due')));

    // ⚠⚠ ABSENT, not greyed out. Telling somebody there is a row here they may
    // not read is its own small leak.
    $this->actingAs($this->staff)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => ! collect(itemsOn($days, '2026-09-15'))
            ->contains(fn ($i) => $i['type'] === 'invoice_due')));
});

it('never puts an expected delivery in front of somebody who may not open purchasing', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'SUP-1', 'name' => 'Mill']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'code' => 'WH1', 'name' => 'Main']);
    PurchaseOrder::create([
        'business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
        'po_number' => 'PO-1', 'order_date' => '2026-09-01', 'expected_date' => '2026-09-20',
        'status' => 'open', 'created_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => collect(itemsOn($days, '2026-09-20'))
            ->contains(fn ($i) => $i['type'] === 'purchase_order')));

    $this->actingAs($this->staff)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => itemsOn($days, '2026-09-20') === []));
});

it('keeps a private entry out of everybody else"s calendar', function () {
    CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Doctor', 'starts_at' => '2026-09-05 09:00',
        'is_private' => true, 'created_by' => $this->staff->id,
    ]);
    CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Team meeting', 'starts_at' => '2026-09-05 10:00',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => count(itemsOn($days, '2026-09-05')) === 2));

    // ⚠ The owner sees the shared one and not the private one. A calendar
    // people cannot keep anything personal in is one they stop using.
    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => count(itemsOn($days, '2026-09-05')) === 1
            && itemsOn($days, '2026-09-05')[0]['title'] === 'Team meeting'));
});

it('answers 404 rather than 403 when somebody reaches for another person"s private entry', function () {
    $event = CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Doctor', 'starts_at' => '2026-09-05 09:00',
        'is_private' => true, 'created_by' => $this->staff->id,
    ]);

    // ⚠⚠ 403 would confirm that this event exists and belongs to a colleague,
    // which is exactly what private was for.
    $this->actingAs($this->owner)->delete(route('calendar.destroy', $event->id))->assertNotFound();

    expect(CalendarEvent::count())->toBe(1);
});

it('lets anyone who manages the calendar correct a shared entry', function () {
    $event = CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Team meeting', 'starts_at' => '2026-09-05 10:00',
        'created_by' => $this->staff->id,
    ]);

    // ⚠ On a nine-person business, requiring the author means a meeting nobody
    // can move the week its author is on leave.
    $this->actingAs($this->owner)->put(route('calendar.update', $event->id), [
        'title' => 'Team meeting, moved',
        'starts_at' => '2026-09-06 10:00',
    ])->assertRedirect();

    expect($event->fresh()->title)->toBe('Team meeting, moved');
});

it('refuses an entry that ends before it starts', function () {
    $this->actingAs($this->owner)->post(route('calendar.store'), [
        'title' => 'Backwards',
        'starts_at' => '2026-09-10 10:00',
        'ends_at' => '2026-09-09 10:00',
    ])->assertSessionHasErrors('ends_at');

    expect(CalendarEvent::count())->toBe(0);
});

it('accepts an entry that starts and ends on the same day', function () {
    // ⚠ after_or_equal, not after: the ordinary case is not an error.
    $this->actingAs($this->owner)->post(route('calendar.store'), [
        'title' => 'One morning',
        'starts_at' => '2026-09-10 09:00',
        'ends_at' => '2026-09-10 11:00',
    ])->assertRedirect();

    expect(CalendarEvent::count())->toBe(1);
});

it('shows an entry on every day it spans, not only the day it starts', function () {
    CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Trade fair', 'starts_at' => '2026-09-01 09:00',
        'ends_at' => '2026-09-03 18:00', 'created_by' => $this->owner->id,
    ]);

    // ⚠⚠ The window asked of the feed is the whole GRID. A month-long event
    // starting in August must still appear in September's grid, which is what
    // the overlapping scope is for.
    CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Long campaign', 'starts_at' => '2026-08-20 09:00',
        'ends_at' => '2026-09-25 18:00', 'created_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => collect($days)
            ->flatMap(fn ($d) => collect((array) $d)->get('items', []))
            ->pluck('title')
            ->contains('Long campaign')));
});

it('never shows one business the calendar of another', function () {
    CalendarEvent::create([
        'business_id' => $this->business->id, 'title' => 'Ours', 'starts_at' => '2026-09-05 09:00', 'created_by' => $this->owner->id,
    ]);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    CalendarEvent::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'title' => 'Theirs', 'starts_at' => '2026-09-05 09:00', 'created_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => count(itemsOn($days, '2026-09-05')) === 1
            && itemsOn($days, '2026-09-05')[0]['title'] === 'Ours'));
});

it('refuses the calendar to a login that holds no calendar permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/calendar')->assertForbidden();
});

// CRM-013 (audit 14 Oct 2026): history showed as upcoming money and goods. A
// settled invoice or bill (here owing nothing, a zero total being the simplest
// settled document) and a received or cancelled PO are not "coming".
it('leaves settled invoices, settled bills and finished purchase orders off the calendar', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Shop A']);
    foreach (['INV-OWING' => 100, 'INV-SETTLED' => 0] as $number => $total) {
        SalesInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-15',
            'subtotal' => $total, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => $total,
            'created_by' => $this->owner->id,
        ]);
    }

    $supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'SUP-1', 'name' => 'Mill']);
    foreach (['BILL-OWING' => 100, 'BILL-SETTLED' => 0] as $number => $total) {
        SupplierBill::create([
            'business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'bill_number' => $number,
            'bill_date' => '2026-09-01', 'due_date' => '2026-09-16', 'total' => $total,
            'created_by' => $this->owner->id,
        ]);
    }

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'code' => 'WH1', 'name' => 'Main']);
    foreach (['open', 'partially_received', 'received', 'cancelled'] as $i => $status) {
        PurchaseOrder::create([
            'business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'po_number' => 'PO-'.$status, 'order_date' => '2026-09-01', 'expected_date' => '2026-09-20',
            'status' => $status, 'created_by' => $this->owner->id,
        ]);
    }

    $titles = fn ($days, string $date) => collect(itemsOn($days, $date))->map(fn ($i) => ((array) $i)['title'])->sort()->values()->all();

    $this->actingAs($this->owner)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page
            ->where('days', fn ($days) => $titles($days, '2026-09-15') === ['INV-OWING'])
            ->where('days', fn ($days) => $titles($days, '2026-09-16') === ['BILL-OWING'])
            ->where('days', fn ($days) => $titles($days, '2026-09-20') === ['PO-open', 'PO-partially_received']));
});

// Add and edit are one popup opened from the picked day or the entry since
// 14 Oct 2026 (the form under the day is gone). The page carries what the popup
// fills an edit with - the entry's id, title, day and its Private label - and
// the popup saves through the same routes, in the shape it sends.
it('carries what the add and edit popup needs, and saves through the same routes', function () {
    $this->actingAs($this->staff)->post(route('calendar.store'), [
        'title' => 'Doctor', 'description' => '', 'location' => 'Square Hospital',
        'starts_at' => '2026-09-05T09:00', 'ends_at' => '', 'all_day' => true, 'is_private' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $event = CalendarEvent::query()->sole();

    $this->actingAs($this->staff)->get('/calendar?month=2026-09')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('calendar/index')
            ->where('can.manage', true)
            ->where('days', fn ($days) => collect(itemsOn($days, '2026-09-05'))->contains(fn ($i) => $i['id'] === $event->id
                && $i['title'] === 'Doctor' && $i['date'] === '2026-09-05' && $i['label'] === 'Private'
                // The entry's own fields, so an edit no longer clears the place,
                // the end, the all-day flag or the description (14 Oct 2026).
                && $i['edit']['location'] === 'Square Hospital' && $i['edit']['starts_at'] === '2026-09-05T09:00'
                && $i['edit']['all_day'] === true && $i['edit']['is_private'] === true)));

    // An edit keeps the entry private: the popup reads Private off the label.
    $this->actingAs($this->staff)->put(route('calendar.update', $event->id), [
        'title' => 'Doctor, follow-up', 'description' => '', 'location' => '',
        'starts_at' => '2026-09-05T09:00', 'ends_at' => '', 'all_day' => true, 'is_private' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($event->fresh()->title)->toBe('Doctor, follow-up')
        ->and($event->fresh()->is_private)->toBeTrue();
});
