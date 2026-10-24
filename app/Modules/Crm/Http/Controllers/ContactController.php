<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Actions\PullCrmRecordsIntoPhoneBook;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Support\ContactDuplicateRule;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SaveAnyway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The phone book.
 *
 * ⚠⚠ SEARCH LOOKS AT `phone_normalized`, NOT AT `phone`. Somebody hunting for
 * 01712345678 must find the row that was typed as +8801712345678, which is the
 * whole reason the matching column exists. Searching the raw column would work
 * on the developer's own test data and fail on the real book.
 *
 * ⚠ Nothing here deletes. A contact is deactivated, because a number that was in
 * a group last month is part of the answer to "who did we message" and deleting
 * the row destroys that answer while leaving the send log pointing at nothing.
 */
class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'group' => $request->query('group'),
            'status' => $request->query('status', 'active'),
        ];

        $contacts = Contact::query()
            ->visibleLeadsOnly()
            ->with(['groups:id,name'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = $filters['search'];
                // ⚠ Normalised separately, so a search for "+880 1712-345678"
                // finds the row stored as 01712345678.
                $phone = PhoneIdentity::normalize($term);

                $query->where(function (Builder $q) use ($term, $phone) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('company', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");

                    if ($phone !== null) {
                        $q->orWhere('phone_normalized', 'like', "%{$phone}%");
                    }
                });
            })
            ->when($filters['group'] !== null && $filters['group'] !== '', fn (Builder $q) => $q->whereHas('groups', fn (Builder $g) => $g->where('contact_groups.id', $filters['group'])))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'company' => $contact->company,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'address' => $contact->address,
                'source' => $contact->source,
                'note' => $contact->note,
                'is_active' => $contact->is_active,
                // ⚠ Shown so nobody edits a shadow row and wonders why the
                // customer record did not change.
                'linked' => $contact->linkedLabel(),
                'groups' => $contact->groups->map(fn (ContactGroup $g) => ['id' => $g->id, 'name' => $g->name])->values(),
            ]);

        return Inertia::render('crm/contacts/index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'groups' => ContactGroup::query()->orderBy('name')
                ->withCount('contacts')
                ->get(['id', 'name', 'description', 'is_active'])
                ->map(fn (ContactGroup $g) => [
                    'id' => $g->id, 'name' => $g->name, 'description' => $g->description,
                    'is_active' => $g->is_active, 'contacts_count' => $g->contacts_count,
                ]),
            'totals' => [
                'all' => Contact::query()->visibleLeadsOnly()->count(),
                'active' => Contact::query()->visibleLeadsOnly()->where('is_active', true)->count(),
                'reachable_by_sms' => Contact::query()->visibleLeadsOnly()->where('is_active', true)->whereNotNull('phone_normalized')->count(),
                'reachable_by_email' => Contact::query()->visibleLeadsOnly()->where('is_active', true)->whereNotNull('email')->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('crm.contacts.manage'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): the quick add asks "Possible
        // duplicate" (same phone or name) BEFORE saving; Save anyway is kept.
        $ack = SaveAnyway::ask(app(ContactDuplicateRule::class), $data, $request, (int) app(BusinessContext::class)->id(), 'Possible duplicate. Is this a different person?');

        $contact = DB::transaction(function () use ($data, $request, $ack) {
            $contact = new Contact($data);
            $contact->created_by = $request->user()->id;
            $contact->save();
            SaveAnyway::record($ack, $contact);

            return $contact;
        });

        return back()->with('success', $this->savedMessage($contact));
    }

    /**
     * Many people from one popup (Joy, 14 Oct 2026: "also will be bulk adding
     * system"), typed or pasted from a spreadsheet, optionally straight into a group.
     *
     * ⚠ All or nothing, like the file importer: one bad row refuses the batch and
     * the error names the row. A half-saved batch cannot be run again safely.
     *
     * ⚠ Capped at BULK_LIMIT. A bigger list belongs in the CSV importer, which
     * shows what it will do before it writes; a popup has no review step.
     */
    public const BULK_LIMIT = 200;

    public function bulkStore(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'group_id' => ['nullable', Rule::exists('contact_groups', 'id')->where('business_id', $businessId)],
            'source' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.company' => ['nullable', 'string', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:40', 'required_without:rows.*.email'],
            'rows.*.email' => ['nullable', 'email', 'max:255', 'required_without:rows.*.phone'],
            'rows.*.address' => ['nullable', 'string', 'max:255'],
            'rows.*.source' => ['nullable', 'string', 'max:255'],
            'rows.*.note' => ['nullable', 'string', 'max:2000'],
        ], [
            'rows.max' => 'A popup takes up to '.self::BULK_LIMIT.' people at a time. Use the contact file import for a longer list.',
            'rows.*.phone.required_without' => 'Row :position: give a phone number or an email address.',
            'rows.*.email.required_without' => 'Row :position: give a phone number or an email address.',
            'rows.*.name.required' => 'Row :position: a name is needed.',
            'rows.*.email.email' => 'Row :position: this is not an email address.',
        ]);

        $shared = [];

        $contacts = DB::transaction(function () use ($data, $request, &$shared) {
            $created = [];

            foreach ($data['rows'] as $row) {
                $contact = new Contact(array_merge(
                    array_intersect_key($row, array_flip(['name', 'company', 'phone', 'email', 'address', 'note'])),
                    ['source' => filled($row['source'] ?? null) ? $row['source'] : ($data['source'] ?? null)],
                ));
                $contact->created_by = $request->user()->id;

                // ⚠ Asked BEFORE saving each row, inside the transaction, so a
                // number repeated within the batch warns on its second row as
                // well as a number already in the book. Warns, never blocks.
                $normalized = PhoneIdentity::normalize($contact->phone);

                if ($normalized !== null && Contact::query()->where('phone_normalized', $normalized)->exists()) {
                    $shared[] = $contact->name;
                }

                $contact->save();
                $created[] = $contact->id;
            }

            if (! empty($data['group_id'])) {
                ContactGroup::findOrFail($data['group_id'])->contacts()->syncWithoutDetaching($created);
            }

            return $created;
        });

        $message = count($contacts).' contact(s) added'
            .(! empty($data['group_id']) ? ' to '.ContactGroup::find($data['group_id'])?->name : '').'.';

        if ($shared !== []) {
            $message .= ' Already in the book under the same number: '.implode(', ', array_slice($shared, 0, 10))
                .(count($shared) > 10 ? ' and '.(count($shared) - 10).' more' : '').'.';
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        // ⚠⚠ A shadow of a lead or a customer is not editable here. Letting the
        // phone book rename somebody's customer record through the back door
        // would give the business two names for one buyer and no way to tell
        // which one the invoices used.
        if ($contact->linked_type !== null) {
            return back()->withErrors([
                'name' => 'This contact mirrors a '.strtolower((string) $contact->linkedLabel()).' record. Change it there and it will follow.',
            ]);
        }

        $contact->fill($this->validated($request, $contact))->save();

        return back()->with('success', $this->savedMessage($contact));
    }

    /**
     * Deactivate, never delete.
     */
    public function deactivate(Contact $contact): RedirectResponse
    {
        $contact->update(['is_active' => false]);

        return back()->with('success', "{$contact->name} will not be included in any future send.");
    }

    public function reactivate(Contact $contact): RedirectResponse
    {
        $contact->update(['is_active' => true]);

        return back()->with('success', "{$contact->name} is back in the phone book.");
    }

    /**
     * Put the selected people into a group.
     *
     * ⚠ `syncWithoutDetaching` rather than `attach`: attaching somebody already
     * in the group throws on the unique index, and a bulk action that fails
     * because one of forty people was already a member is a bad tool.
     */
    public function addToGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', Rule::exists('contact_groups', 'id')],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => [Rule::exists('contacts', 'id')],
        ]);

        $group = ContactGroup::findOrFail($data['group_id']);

        // ⚠ Re-read through the tenant-scoped model rather than trusting the ids
        // in the request: `exists` proves the row is there, not that it belongs
        // to this business.
        $ids = Contact::query()->whereIn('id', $data['contact_ids'])->pluck('id')->all();

        $group->contacts()->syncWithoutDetaching($ids);

        return back()->with('success', count($ids).' contact(s) added to '.$group->name.'.');
    }

    public function removeFromGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', Rule::exists('contact_groups', 'id')],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => [Rule::exists('contacts', 'id')],
        ]);

        $group = ContactGroup::findOrFail($data['group_id']);
        $ids = Contact::query()->whereIn('id', $data['contact_ids'])->pluck('id')->all();

        $group->contacts()->detach($ids);

        return back()->with('success', count($ids).' contact(s) removed from '.$group->name.'.');
    }

    /**
     * Build phone book entries from the CRM records that already exist.
     */
    public function pullFromCrm(Request $request, PullCrmRecordsIntoPhoneBook $pull): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['lead', 'customer'])],
        ]);

        $result = $pull->handle($data['type']);

        return back()->with('success', sprintf(
            '%d added, %d refreshed, %d skipped for having neither a phone nor an email.',
            $result['created'], $result['refreshed'], $result['skipped'],
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request, ?Contact $contact = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            // ⚠⚠ Phone and email are each optional and at least one is required.
            // A contact with neither cannot be sent anything, and would sit in
            // every audience total as a person who can never be reached.
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'address' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'phone.required_without' => 'Give a phone number or an email address, or there is no way to reach this person.',
            'email.required_without' => 'Give a phone number or an email address, or there is no way to reach this person.',
        ]);
    }

    /**
     * ⚠ Duplicates WARN and never block (C-CRM-12, C-CRM-13): households, shops
     * and families legitimately share one number, and refusing the second entry
     * would lose a real person to keep a table tidy.
     */
    private function savedMessage(Contact $contact): string
    {
        $duplicates = $contact->possibleDuplicates()->where('is_active', true)->pluck('name');

        if ($duplicates->isEmpty()) {
            return "{$contact->name} saved.";
        }

        return "{$contact->name} saved. That number is also filed under: ".$duplicates->implode(', ').'.';
    }
}
