<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Actions\LogCrmActivity;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, string $type, int $id): Response
    {
        $subject = $this->resolveSubject($type, $id);

        $activities = CrmActivity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('recordedBy:id,name')
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
            ]);

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
            ],
            'activities' => $activities,
            'tasks' => $tasks,
            'activityTypes' => collect(ActivityType::selectable())
                ->map(fn (ActivityType $t) => [
                    'value' => $t->value, 'label' => $t->label(),
                    'has_direction' => $t->hasDirection(), 'has_duration' => $t->hasDuration(),
                ])->all(),
            'directions' => collect(ActivityDirection::cases())
                ->map(fn (ActivityDirection $d) => ['value' => $d->value, 'label' => $d->label()])->all(),
            'assignableUsers' => $this->assignableUsers(),
            'canRecord' => $request->user()?->can('crm.leads.manage') ?? false,
            'canAssign' => $request->user()?->can('crm.leads.assign') ?? false,
            'now' => now()->toDateTimeString(),
        ]);
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
     * The work queue: open follow-ups, ordered by when they are due.
     */
    public function queue(Request $request): Response
    {
        $user = $request->user();

        // ⚠⚠ C-CRMB-10. Without `crm.tasks.view.all` a person sees their OWN
        // queue. Otherwise every agent reads every other agent's follow-ups,
        // which is both noise and a quiet disclosure.
        $seesEverybody = $user?->can('crm.tasks.view.all') ?? false;

        $tasks = CrmTask::query()
            ->when(! $seesEverybody, fn ($q) => $q->where('assigned_to_user_id', $user->id))
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
