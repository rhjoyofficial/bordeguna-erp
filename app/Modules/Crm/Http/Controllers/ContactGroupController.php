<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The named lists a send is aimed at.
 *
 * ⚠ A group is never deleted, only deactivated. Once this feeds a send, "who
 * received the Eid message" is answered by the group, and deleting it would
 * leave that question permanently unanswerable while the send log still claims
 * the message went somewhere.
 */
class ContactGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        ContactGroup::create($data);

        return back()->with('success', "Group {$data['name']} created.");
    }

    public function update(Request $request, ContactGroup $group): RedirectResponse
    {
        $group->update($this->rules($request, $group));

        return back()->with('success', "Group {$group->name} saved.");
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(Request $request, ?ContactGroup $group = null): array
    {
        $businessId = app(BusinessContext::class)->id();

        return $request->validate([
            // ⚠ Unique per BUSINESS, and the rule says so explicitly. Without
            // the business_id clause the check runs across every tenant and one
            // business naming a group "Dealers" would stop every other business
            // ever using that name.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('contact_groups', 'name')
                    ->where(fn ($q) => $q->where('business_id', $businessId))
                    ->ignore($group?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
