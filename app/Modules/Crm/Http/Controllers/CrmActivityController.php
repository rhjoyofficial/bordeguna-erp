<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Actions\LogCrmActivity;
use App\Modules\Crm\Actions\RecordProductInterest;
use App\Modules\Crm\Corrections\CrmActivityActionPolicy;
use App\Modules\Crm\Corrections\LeadActionPolicy;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\SampleResult;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmProductInterest;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\DistributorInspection;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\SupplierLeadSample;
use App\Modules\Crm\Support\LeadFormOptions;
use App\Modules\Crm\Support\LeadPresenter;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Crm\Support\MapLink;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Purchasing\Support\CorrectionReasons;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The timeline of a lead or a customer: what happened, and what is due.
 *
 * ⚠ Reading needs `crm.leads.view`; recording needs `crm.leads.manage`. No new
 * permission is introduced for activities - a new ACL name for an existing job
 * is noise, and every extra permission is one more thing to get wrong.
 */
class CrmActivityController extends Controller
{
    /**
     * ⚠⚠ Resolved through the SCOPED model, so a subject from another business
     * comes back null and 404s rather than rendering somebody else's history.
     */
    private function resolveSubject(string $type, int $id): Model
    {
        $model = match ($type) {
            'lead' => Lead::find($id),
            'customer' => Customer::find($id),
            default => null,
        };

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * ⚠⚠ A task or an interest is reached by ITS OWN id, not the lead's, so the
     * lead's visibility scope never runs on that route. Resolving the subject
     * here puts it back: a follow-up on a lead this person may not see 404s
     * exactly as the lead's timeline does, instead of being completable by
     * anybody who guesses a number.
     */
    private function guardSubjectIsVisible(string $subjectType, int $subjectId): void
    {
        if ($subjectType === (new Lead)->getMorphClass()) {
            abort_if(Lead::query()->whereKey($subjectId)->doesntExist(), 404);
        }
    }

    public function show(Request $request, string $type, int $id): Response
    {
        $subject = $this->resolveSubject($type, $id);
        $corrections = app(CrmActivityActionPolicy::class);

        $activities = CrmActivity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with(['recordedBy:id,name', 'attachments'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CrmActivity $a) => [
                'id' => $a->id,
                'kind' => 'activity',
                'type' => $a->type->value,
                'label' => $a->label(),
                // ⚠ occurred_at, never created_at. A call made this morning and
                // typed up tonight happened this morning.
                'at' => $a->occurred_at?->toDateTimeString(),
                'summary' => $a->summary,
                'body' => $a->body,
                'outcome' => $a->outcome,
                'duration_seconds' => $a->duration_seconds,
                'by' => $a->recordedBy?->name,
                // ⭐ CRM slice 2d: a visit's optional proof.
                'location' => $this->locationRow($a),
                'photos' => $a->attachments
                    ->where('collection', CrmActivity::PHOTOS)
                    ->map(fn (Attachment $file) => ['id' => $file->id, 'name' => $file->original_name, 'url' => $file->url()])
                    ->values()->all(),
                // ⭐ Corrections wave 3 (24 Oct 2026): what the author may do
                // with it (Edit, Delete within 24 hours, Correct after), the
                // marks a reader needs (Edited, Corrected), and the values the
                // edit popup opens with.
                'direction' => $a->direction?->value,
                'has_direction' => $a->type->hasDirection(),
                'has_duration' => $a->type->hasDuration(),
                'edited' => $a->edited_at !== null,
                'corrected' => $a->isCorrected(),
                'correction_reason' => $a->correction_reason,
                'is_correction' => $a->correction_of_id !== null,
                'seen' => $a->updated_at?->toDateTimeString(),
                'actions' => $corrections->actionsFor($a, $request->user())->toArray(),
            ]);

        // ⭐ Plan D14: the pinned location is the FIRST visit that carried GPS.
        $pinned = CrmActivity::query()->locatedVisitsOf($subject)->with('recordedBy:id,name')->first();

        $tasks = CrmTask::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with(['assignedTo:id,name', 'completedBy:id,name'])
            ->orderBy('due_at')
            ->get()
            ->map(fn (CrmTask $t) => [
                'id' => $t->id,
                'kind' => 'task',
                'title' => $t->title,
                'note' => $t->note,
                'at' => $t->due_at?->toDateTimeString(),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'is_overdue' => $t->isOverdue(),
                'assigned_to' => $t->assignedTo?->name,
                'assigned_to_user_id' => $t->assigned_to_user_id,
                'completed_at' => $t->completed_at?->toDateTimeString(),
                'completed_by' => $t->completedBy?->name,
            ]);

        return Inertia::render('crm/timeline', [
            'subject' => [
                'kind' => $type,
                'id' => $subject->getKey(),
                'name' => $subject->name,
                'phone' => $subject->phone,
                // A converted lead links to the customer it became, so the whole
                // story stays reachable without copying any history.
                'converted_customer_id' => $type === 'lead' ? $subject->converted_customer_id : null,
                'converted_supplier_id' => $type === 'lead' ? $subject->converted_supplier_id : null,
                'lead_type' => $type === 'lead' ? $subject->lead_type->value : null,
                'pinned' => $pinned === null ? null : $this->locationRow($pinned) + [
                    'visited_at' => $pinned->occurred_at?->toDateTimeString(),
                    'by' => $pinned->recordedBy?->name,
                ],
            ],
            // ⭐ CRM slice 2c. Sent for every lead, not only for the matching type:
            // a lead whose type was corrected keeps what was recorded under the
            // old one, and hiding it would look like it was lost. The screen
            // offers a NEW record only on the matching type.
            'inspections' => $subject instanceof Lead ? $this->inspections($subject) : [],
            'samples' => $subject instanceof Lead ? $this->samples($subject) : [],
            'sampleResults' => collect(SampleResult::cases())
                ->map(fn (SampleResult $r) => ['value' => $r->value, 'label' => $r->label()])->all(),
            'canInspect' => $request->user()?->can('crm.leads.verify') ?? false,
            'canJudgeSample' => $request->user()?->can('crm.samples.judge') ?? false,
            'activities' => $activities,
            'tasks' => $tasks,
            'activityTypes' => collect(ActivityType::selectable())
                ->map(fn (ActivityType $t) => [
                    'value' => $t->value, 'label' => $t->label(),
                    'has_direction' => $t->hasDirection(), 'has_duration' => $t->hasDuration(),
                ])->all(),
            'directions' => collect(ActivityDirection::cases())
                ->map(fn (ActivityDirection $d) => ['value' => $d->value, 'label' => $d->label()])->all(),
            // ⚠ Newest request first: the most recent thing somebody asked for
            // is what the next call is about.
            'interests' => CrmProductInterest::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->with(['product:id,sku,name,is_active', 'category:id,name', 'brand:id,name',
                    'requestedUnit:id,name,abbreviation', 'recordedBy:id,name'])
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CrmProductInterest $i) => [
                    'id' => $i->id,
                    'what' => $i->what(),
                    'kind' => $i->product_id !== null ? 'product' : ($i->category_id !== null ? 'category' : 'brand'),
                    'sku' => $i->product?->sku,
                    // ⚠ Surfaced rather than hidden: somebody asking for an
                    // inactive product is a demand signal, not an error, but
                    // the person reading the screen needs to know it is not
                    // currently sold.
                    'product_is_active' => $i->product_id === null ? null : (bool) $i->product?->is_active,
                    'quantity' => $i->requested_quantity === null
                        ? null
                        : rtrim(rtrim((string) $i->requested_quantity, '0'), '.'),
                    // ⚠⚠ The unit they SAID, which may differ from the
                    // product's stock unit. Shown as stated.
                    'unit' => $i->requestedUnit?->abbreviation,
                    'note' => $i->note,
                    'requested_at' => $i->requested_at?->toDateTimeString(),
                    'status' => $i->status->value,
                    'status_label' => $i->status->label(),
                    'closed_note' => $i->closed_note,
                    'by' => $i->recordedBy?->name,
                ])->all(),

            'interestStatuses' => collect(InterestStatus::closable())
                ->map(fn (InterestStatus $s) => ['value' => $s->value, 'label' => $s->label()])->all(),

            'products' => Product::query()->orderBy('name')->get(['id', 'sku', 'name', 'is_active'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'label' => $p->is_active ? "{$p->sku} - {$p->name}" : "{$p->sku} - {$p->name} (inactive)",
                ])->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->all(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->all(),
            'units' => UnitOfMeasure::query()->orderBy('name')->get(['id', 'name', 'abbreviation'])->all(),

            'assignableUsers' => $this->assignableUsers(),
            'canRecord' => $request->user()?->can('crm.leads.manage') ?? false,
            // ⭐ Wave 3: the reason lists of the correction dialogs, and the
            // Owner's link to what was deleted here.
            'correctionReasons' => CorrectionReasons::for(['correction', 'deletion', 'reversal']),
            'isOwner' => Owners::is($request->user()),
            'canAssign' => $request->user()?->can('crm.leads.assign') ?? false,
            'now' => now()->toDateTimeString(),

            // ⭐ Round 3 (16 Oct 2026): the lead page. Its head card reads the
            // same row as the lead list, and Edit and Make a customer open the
            // same popups with the same options, fetched only when one opens.
            ...($subject instanceof Lead ? $this->leadPage($request, $subject) : []),
        ]);
    }

    /**
     * What the lead page adds to the timeline: the lead's row, its head facts,
     * its open deals and the permissions of its buttons.
     *
     * @return array<string, mixed>
     */
    private function leadPage(Request $request, Lead $subject): array
    {
        $user = $request->user();
        /** @var Lead $lead */
        $lead = LeadPresenter::withListColumns(Lead::query()->with([...LeadPresenter::RELATIONS, 'lostReason:id,name']))
            ->whereKey($subject->getKey())
            ->firstOrFail();
        $source = $lead->firstAttribution()?->loadMissing(['leadSource:id,name', 'campaign:id,name']);

        return [
            'lead' => LeadPresenter::row($lead),
            'leadHead' => [
                'source' => $source?->leadSource?->name,
                'campaign' => $source?->campaign?->name,
                'lost_reason' => $lead->lostReason?->name,
            ],
            // ⚠ The deal board's own rule: open deals of this lead the reader may see.
            'deals' => DB::table('crm_opportunities')
                ->where('crm_opportunities.business_id', $lead->business_id)
                ->where('crm_opportunities.lead_id', $lead->id)
                ->where('crm_opportunities.status', 'open')
                ->tap(fn ($query) => app(LeadVisibility::class)->restrict($query, $user, 'crm_opportunities.assigned_to_user_id'))
                ->leftJoin('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_opportunities.stage_id')
                ->orderByDesc('crm_opportunities.value')
                ->get(['crm_opportunities.id', 'crm_opportunities.name', 'crm_opportunities.value', 'crm_pipeline_stages.name as stage'])
                ->map(fn ($deal) => ['id' => $deal->id, 'name' => $deal->name, 'value' => (float) $deal->value, 'stage' => $deal->stage])
                ->all(),
            'selectableStatuses' => collect(LeadStatus::selectable())
                ->map(fn (LeadStatus $status) => ['value' => $status->value, 'label' => $status->label()])->values()->all(),
            'lostReasons' => Reason::forContext('lead_lost')->active()->orderBy('name')->get(['id', 'name'])->all(),
            'leadCan' => [
                'convert' => $user->can('crm.leads.convert'),
                'convert_supplier' => LeadFormOptions::mayConvertSuppliers($user),
                'verify' => $user->can('crm.leads.verify'),
            ],
            // ⭐ Corrections wave 3 (24 Oct 2026): Reverse conversion or Delete
            // (the policy picks one), and Reopen for a lost lead, each with
            // the sentence that greys it out.
            'leadActions' => app(LeadActionPolicy::class)->actionsFor($lead, $user)->toArray(),
            'leadReopen' => $lead->status === LeadStatus::Lost && $user->can(LeadActionPolicy::REOPENER)
                ? ['blocker' => app(LeadActionPolicy::class)->reopenBlocker($lead, $user)]
                : null,
            // ⚠ Optional: the customers, suppliers and lists behind the popups
            // are read only when Edit or Make a customer is opened.
            'leadForm' => Inertia::optional(fn () => LeadFormOptions::for($user, (int) app(BusinessContext::class)->id())),
        ];
    }

    public function store(Request $request, string $type, int $id, LogCrmActivity $action): RedirectResponse
    {
        $subject = $this->resolveSubject($type, $id);

        $data = $request->validate([
            'type' => ['required', Rule::enum(ActivityType::class)],
            'direction' => ['nullable', Rule::enum(ActivityDirection::class)],
            'occurred_at' => ['nullable', 'date'],
            'summary' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string', 'max:30'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            // ⭐ CRM slice 2d: a visit's optional proof (Joy's answer 2). The
            // rules about WHEN they are allowed live in LogCrmActivity; these are
            // only the shapes.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude,gps_accuracy_meters'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude,gps_accuracy_meters'],
            'gps_accuracy_meters' => ['nullable', 'numeric', 'min:0', 'required_with:latitude,longitude'],
            'photos' => ['nullable', 'array', 'max:'.LogCrmActivity::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'latitude.required_with' => 'The location came through incomplete. Add it again, or record the visit without it.',
            'longitude.required_with' => 'The location came through incomplete. Add it again, or record the visit without it.',
            'gps_accuracy_meters.required_with' => 'The location came through incomplete. Add it again, or record the visit without it.',
        ]);

        $action->handle(
            subject: $subject,
            type: ActivityType::from($data['type']),
            summary: $data['summary'],
            recordedBy: $request->user(),
            occurredAt: $data['occurred_at'] ?? null,
            direction: isset($data['direction']) ? ActivityDirection::from($data['direction']) : null,
            body: $data['body'] ?? null,
            outcome: $data['outcome'] ?? null,
            durationSeconds: $data['duration_seconds'] ?? null,
            location: isset($data['latitude']) ? [
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy' => $data['gps_accuracy_meters'],
            ] : null,
            photos: $request->file('photos', []),
        );

        return back()->with('success', 'Recorded.');
    }

    public function storeTask(Request $request, string $type, int $id): RedirectResponse
    {
        $subject = $this->resolveSubject($type, $id);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'due_at' => ['required', 'date'],
            // ⚠⚠ C-CRMB-5. Membership is the business_user PIVOT. `users` has NO
            // business_id column, so a scoped exists rule against `users` would
            // match nothing and silently reject every valid agent.
            'assigned_to_user_id' => ['nullable', 'integer',
                Rule::exists('business_user', 'user_id')->where('business_id', $businessId)],
        ]);

        // ⚠ Assigning to somebody else is a manager's decision. Without the
        // permission, a task is your own.
        $assignee = $data['assigned_to_user_id'] ?? null;

        if ($assignee !== null && (int) $assignee !== $request->user()->id
            && ! $request->user()->can('crm.leads.assign')) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'You may create follow-ups for yourself, but not assign them to somebody else.',
            ]);
        }

        CrmTask::create([
            'business_id' => $businessId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_at' => $data['due_at'],
            'assigned_to_user_id' => $assignee ?? $request->user()->id,
            'status' => TaskStatus::Open,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Follow-up added.');
    }

    public function updateTask(Request $request, CrmTask $task): RedirectResponse
    {
        $this->guardSubjectIsVisible($task->subject_type, (int) $task->subject_id);

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'due_at' => ['nullable', 'date'],
            'assigned_to_user_id' => ['nullable', 'integer',
                Rule::exists('business_user', 'user_id')->where('business_id', $businessId)],
        ]);

        $status = TaskStatus::from($data['status']);

        if (array_key_exists('assigned_to_user_id', $data)
            && (int) $data['assigned_to_user_id'] !== $task->assigned_to_user_id
            && ! $request->user()->can('crm.leads.assign')) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Reassigning a follow-up needs the assign permission.',
            ]);
        }

        $task->fill([
            'status' => $status,
            'due_at' => $data['due_at'] ?? $task->due_at,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? $task->assigned_to_user_id,
        ]);

        // ⚠⚠ C-CRMB-6. Completing a task records WHO and WHEN and stops there.
        // It does NOT invent an activity - what happened on that call is a
        // statement a person makes, and generating one would put words in their
        // mouth that later read as evidence.
        if ($status === TaskStatus::Done && $task->completed_at === null) {
            $task->completed_at = now();
            $task->completed_by = $request->user()->id;
        }

        // Reopening clears the completion, or the row claims two truths at once.
        if ($status === TaskStatus::Open) {
            $task->completed_at = null;
            $task->completed_by = null;
        }

        $task->save();

        return back()->with('success', 'Follow-up updated.');
    }

    /**
     * Record that somebody asked for something.
     *
     * ⚠⚠ ALWAYS A NEW ROW. "Wants 2kg" in June and "wants 10kg for Eid" in
     * August are two different business facts, and the demand history is what
     * tells you whether to stock mango again. An earlier draft used
     * updateOrCreate; that would have destroyed the first statement every time
     * somebody made a second one.
     */
    /**
     * @return array{latitude: string, longitude: string, accuracy_meters: int|null, map_url: string}|null
     */
    private function locationRow(CrmActivity $activity): ?array
    {
        if (! $activity->hasLocation()) {
            return null;
        }

        return [
            'latitude' => $activity->latitude,
            'longitude' => $activity->longitude,
            // Whole metres: "within 12 m" is what anybody reads.
            'accuracy_meters' => $activity->gps_accuracy_meters === null ? null : (int) round((float) $activity->gps_accuracy_meters),
            'map_url' => MapLink::to($activity->latitude, $activity->longitude),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inspections(Lead $lead): array
    {
        return DistributorInspection::query()
            ->where('lead_id', $lead->id)
            ->with(['recordedBy:id,name', 'attachments'])
            ->orderByDesc('inspected_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DistributorInspection $i) => [
                'id' => $i->id,
                'inspected_on' => $i->inspected_on?->toDateString(),
                'notes' => $i->notes,
                'by' => $i->recordedBy?->name,
                'photos' => $i->attachments
                    ->where('collection', DistributorInspection::PHOTOS)
                    ->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => $a->url()])
                    ->values()->all(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samples(Lead $lead): array
    {
        return SupplierLeadSample::query()
            ->where('lead_id', $lead->id)
            ->with(['recordedBy:id,name', 'judgedBy:id,name'])
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierLeadSample $s) => [
                'id' => $s->id,
                'received_on' => $s->received_on?->toDateString(),
                'item' => $s->item,
                'note' => $s->note,
                'by' => $s->recordedBy?->name,
                'result' => $s->result?->value,
                'result_label' => $s->result?->label(),
                'result_note' => $s->result_note,
                'judged_by' => $s->judgedBy?->name,
                'judged_at' => $s->judged_at?->toDateTimeString(),
            ])->all();
    }

    public function storeInterest(Request $request, string $type, int $id, RecordProductInterest $action): RedirectResponse
    {
        $subject = $this->resolveSubject($type, $id);
        $businessId = app(BusinessContext::class)->id();

        // ⚠ Every foreign key is tenant-scoped. A bare exists rule would accept
        // another business's product and the global scope would then hide the
        // row this interest points at.
        // ⛔ Products are NOT restricted to active ones: somebody asking for
        // last season's mango is the demand signal that decides whether to
        // stock it again.
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $businessId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('business_id', $businessId)],
            'requested_quantity' => ['nullable', 'numeric'],
            'requested_unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            'note' => ['nullable', 'string', 'max:255'],
            'requested_at' => ['nullable', 'date'],
        ]);

        $action->handle(
            subject: $subject,
            recordedBy: $request->user(),
            product: empty($data['product_id']) ? null : Product::findOrFail($data['product_id']),
            category: empty($data['category_id']) ? null : Category::findOrFail($data['category_id']),
            brand: empty($data['brand_id']) ? null : Brand::findOrFail($data['brand_id']),
            requestedQuantity: isset($data['requested_quantity']) ? (string) $data['requested_quantity'] : null,
            requestedUnit: empty($data['requested_unit_id']) ? null : UnitOfMeasure::findOrFail($data['requested_unit_id']),
            note: $data['note'] ?? null,
            requestedAt: $data['requested_at'] ?? null,
        );

        return back()->with('success', 'Interest recorded.');
    }

    /**
     * Close an interest. ⚠⚠ NEVER DELETE ONE.
     *
     * A demand signal that quietly disappears is one nobody can audit, and the
     * request itself is immutable - the model refuses any edit to what was
     * originally asked for, so this can only append an outcome.
     */
    public function closeInterest(Request $request, CrmProductInterest $interest): RedirectResponse
    {
        $this->guardSubjectIsVisible($interest->subject_type, (int) $interest->subject_id);

        // ⚠ Bug hunt 16 Oct 2026: a closed interest keeps its outcome. Closing
        // it again (a second tab, a stale page) rewrote who closed it, when
        // and why.
        if (! $interest->status->isOpen()) {
            throw ValidationException::withMessages(['status' => "This interest is already closed ({$interest->status->label()})."]);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(
                collect(InterestStatus::closable())->map(fn (InterestStatus $s) => $s->value)->all()
            )],
            'closed_note' => ['nullable', 'string', 'max:255'],
        ]);

        $interest->update([
            'status' => InterestStatus::from($data['status']),
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
            'closed_note' => $data['closed_note'] ?? null,
        ]);

        return back()->with('success', 'Interest closed.');
    }

    /**
     * The work queue: open follow-ups, ordered by when they are due.
     */
    public function queue(Request $request): Response
    {
        $user = $request->user();

        // ⚠⚠ C-CRMB-10. Without `crm.tasks.view.all` a person sees their OWN
        // queue - and since 15 Sep 2026 their team's too, so the ASM can see an
        // officer's follow-ups going overdue. Otherwise every agent reads every
        // other agent's follow-ups, which is both noise and a quiet disclosure.
        $seesEverybody = $user?->can('crm.tasks.view.all') ?? false;

        $tasks = CrmTask::query()
            ->tap(fn ($q) => app(LeadVisibility::class)->restrictTasks($q, $user))
            ->when($request->string('status')->toString() !== 'all',
                fn ($q) => $q->open())
            ->with(['assignedTo:id,name', 'subject'])
            ->orderBy('due_at')
            ->paginate(50)
            ->withQueryString();

        $tasks->getCollection()->transform(fn (CrmTask $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'due_at' => $t->due_at?->toDateTimeString(),
            'is_overdue' => $t->isOverdue(),
            'status' => $t->status->value,
            'assigned_to' => $t->assignedTo?->name,
            'subject_kind' => $t->subject_type === Lead::class ? 'lead' : 'customer',
            'subject_id' => $t->subject_id,
            'subject_name' => $t->subject?->name,
        ]);

        return Inertia::render('crm/queue', [
            'tasks' => $tasks,
            'seesEverybody' => $seesEverybody,
            'filters' => ['status' => $request->string('status')->toString() ?: 'open'],
        ]);
    }

    /** @return array<int, array{id:int, name:string}> */
    private function assignableUsers(): array
    {
        $businessId = app(BusinessContext::class)->id();

        // ⚠ Through the PIVOT. There is no users.business_id.
        return User::query()
            ->join('business_user', 'business_user.user_id', '=', 'users.id')
            ->where('business_user.business_id', $businessId)
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }
}
