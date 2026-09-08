<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Sections\SectionPayloadValidator;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The composer's own verbs: add a section, edit it, move it, hide it, remove it.
 *
 * ⛔⛔ EVERY WRITE GOES THROUGH `SectionPayloadValidator` AND NOTHING ELSE. A
 * theme cannot loosen a rule, cannot require a field the contract does not have
 * and cannot change what a field means, because two themes rendering this page
 * must show the same facts. Validation belongs to the section type, and this
 * controller is the only door.
 *
 * ⚠ A save tolerates a MISSING required field and refuses a MALFORMED one. An
 * author composing a page over an afternoon has a half-filled hero and should be
 * able to keep it; an author who has typed a script tag into a rich text field
 * should not. Completeness is enforced once, at the publish gate.
 */
class PageSectionController extends Controller
{
    public function store(Request $request, Page $page): RedirectResponse
    {
        $data = $request->validate([
            'type_key' => ['required', 'string', 'max:60'],
            'type_version' => ['required', 'integer', 'min:1'],
        ]);

        $registry = app(SectionTypeRegistry::class);

        // ⛔ Refused here rather than stored and discovered later. A page holding
        // a type nothing defines cannot be validated OR rendered, and the
        // failure would surface at a visitor.
        if (! $registry->has($data['type_key'], $data['type_version'])) {
            throw ValidationException::withMessages([
                'type_key' => "No section type is registered as [{$data['type_key']}@{$data['type_version']}].",
            ]);
        }

        $section = $page->sections()->create([
            'type_key' => $data['type_key'],
            'type_version' => $data['type_version'],
            // ⚠ Appended at the end. A new section landing in the middle of a
            // page somebody has just ordered is the kind of surprise that makes
            // an author distrust the screen.
            'position' => (int) $page->sections()->max('position') + 1,
            'payload' => [],
        ]);

        return back()->with('success', "Added {$section->type()?->label}.");
    }

    public function update(Request $request, Page $page, PageSection $section): RedirectResponse
    {
        $this->refuseForeignSection($page, $section);

        $type = $section->type();

        if ($type === null) {
            throw ValidationException::withMessages([
                'payload' => "This section is a [{$section->identity()}], which this ERP no longer defines. It can be removed, but not edited.",
            ]);
        }

        $payload = $request->input('payload', []);

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['payload' => 'The section content was not sent as a set of fields.']);
        }

        $payload = $this->prune($payload);

        // ⚠ `requireComplete: false`. See the class docblock: unfinished is
        // allowed here and refused at publish.
        $problems = app(SectionPayloadValidator::class)->validate($type, $payload, requireComplete: false);

        if ($problems !== []) {
            throw ValidationException::withMessages(['payload' => $problems]);
        }

        $section->update(['payload' => $payload]);

        return back()->with('success', "{$type->label} saved.");
    }

    /**
     * ⚠ Hiding is not deleting, and the two are deliberately different verbs.
     * Taking a section off the page while keeping its content is the ordinary
     * editorial act: the seasonal product that is out of stock, the
     * certification whose scan has expired. Deleting to hide it means retyping
     * it in three months.
     */
    public function toggleHidden(Page $page, PageSection $section): RedirectResponse
    {
        $this->refuseForeignSection($page, $section);

        $section->update(['is_hidden' => ! $section->is_hidden]);

        return back()->with('success', $section->is_hidden ? 'Section hidden.' : 'Section shown.');
    }

    public function destroy(Page $page, PageSection $section): RedirectResponse
    {
        $this->refuseForeignSection($page, $section);

        $section->delete();

        $this->renumber($page);

        return back()->with('success', 'Section removed.');
    }

    /**
     * Reorder the whole page in one move.
     *
     * ⚠⚠ THE WHOLE ORDER IS POSTED, NOT A PAIR OF SWAPPED IDS, and it is written
     * in one transaction as a contiguous run from zero. Two authors reordering
     * the same page with "move this one up" messages would interleave into an
     * order neither of them asked for, and gaps or ties are how an ordering
     * silently stops being one.
     */
    public function reorder(Request $request, Page $page): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer'],
        ]);

        $existing = $page->sections()->pluck('id')->all();
        $posted = array_map('intval', $data['order']);

        // ⛔ The posted order must be exactly this page's sections, no more and
        // no fewer. A partial order would silently push every omitted section to
        // the end, and an id from another page would move somebody else's
        // content.
        sort($existing);
        $sortedPosted = $posted;
        sort($sortedPosted);

        if ($existing !== $sortedPosted) {
            throw ValidationException::withMessages([
                'order' => 'The order sent does not match this page. Reload the page and try again.',
            ]);
        }

        DB::transaction(function () use ($posted) {
            foreach ($posted as $index => $id) {
                PageSection::where('id', $id)->update(['position' => $index]);
            }
        });

        return back()->with('success', 'Order saved.');
    }

    /* ------------------------------------------------------------------ */

    private function refuseForeignSection(Page $page, PageSection $section): void
    {
        // ⚠ A section is not business-scoped in its own right: it reaches its
        // tenant through its page. So the ownership check is not optional
        // tidiness, it is the scope.
        abort_unless($section->page_id === $page->id, 404);
    }

    /**
     * Drop keys the author cleared, so an emptied optional field goes back to
     * being absent rather than being stored as an empty string.
     *
     * ⚠ This is the shape of a defect that has already cost this group money.
     * On the shops, nullable fields were not sent when blank, so a value could
     * never be CLEARED once set, and "No Discount" could never end a running
     * sale. Here the opposite rule applies for the same reason: an empty string
     * is not a value, and storing one would fail the contract's own length and
     * type checks while looking like content on screen.
     *
     * ⛔ `false` and `0` are values and are kept. Only null, the empty string
     * and the empty array are absence.
     */
    private function prune(array $payload): array
    {
        $pruned = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = array_is_list($value)
                    ? array_values(array_filter(
                        array_map(fn ($entry) => is_array($entry) ? $this->prune($entry) : $entry, $value),
                        fn ($entry) => $entry !== null && $entry !== '' && $entry !== [],
                    ))
                    : $this->prune($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $pruned[$key] = $value;
        }

        return $pruned;
    }

    private function renumber(Page $page): void
    {
        DB::transaction(function () use ($page) {
            foreach ($page->sections()->get() as $index => $section) {
                if ($section->position !== $index) {
                    $section->update(['position' => $index]);
                }
            }
        });
    }
}
