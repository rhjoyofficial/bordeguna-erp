<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\WithdrawShowCauseNotice;
use App\Modules\Hrm\Corrections\HrReasons;
use App\Modules\Hrm\Corrections\ShowCauseNoticeActionPolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Notifications\ShowCauseIssuedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Show cause notices (Joy, 24 Aug 2026): HR reviews a lateness alert and puts
 * the question on formal record; the employee reads and prints their copy
 * under My profile. A notice is immutable once issued - no edit, no delete -
 * so both sides are always holding the same document.
 */
class ShowCauseNoticeController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $this->authorize('hrm.attendance.manage');

        $employeeId = $request->integer('employee_id') ?: null;

        return Inertia::render('hrm/notices/index', [
            'filters' => ['employee_id' => $employeeId],
            'notices' => ShowCauseNotice::with(['employee:id,employee_code,first_name,last_name', 'issuer:id,name'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('issued_at')->limit(100)->get()
                ->map(fn (ShowCauseNotice $notice) => $this->present($notice) + [
                    // ⭐ Corrections wave 3 (24 Oct 2026): Withdraw.
                    'actions' => app(ShowCauseNoticeActionPolicy::class)->actionsFor($notice, $request->user())->toArray(),
                ]),
            'reasons' => HrReasons::for(['withdrawal']),
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('employee_code')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName().' ('.$e->employee_code.')']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId()),
            ],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'context' => ['nullable', 'array'],
            'context.window_label' => ['nullable', 'string', 'max:100'],
            'context.occurrence' => ['nullable', 'integer', 'min:1'],
            'context.month' => ['nullable', 'string', 'max:20'],
            'context.dates' => ['nullable', 'array'],
            'context.dates.*' => ['date'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $notice = ShowCauseNotice::create([
            'employee_id' => $employee->id,
            'issued_by' => $request->user()->id,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'context' => $validated['context'] ?? null,
            'issued_at' => now(),
        ]);

        // The employee's bell rings only if they have a login; the notice
        // stands on record either way and HR can hand over a printed copy.
        if ($employee->user_id !== null && $employee->user !== null) {
            $employee->user->notify(new ShowCauseIssuedNotification(
                'A show cause notice has been issued to you: '.$notice->subject,
                route('hrm.my-notices.show', $notice->id),
            ));

            // The letter reached them: a later withdrawal is shown to them too.
            $notice->update(['notified_at' => now()]);
        }

        return back()->with('success', sprintf('Show cause notice issued to %s.', $employee->fullName()));
    }

    public function show(ShowCauseNotice $notice): Response
    {
        $this->authorize('hrm.attendance.manage');

        return $this->printable($notice, backUrl: route('hrm.notices.index'));
    }

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): Withdraw, with the
     * date first and a reason (WithdrawShowCauseNotice).
     */
    public function withdraw(Request $request, ShowCauseNotice $notice, WithdrawShowCauseNotice $action): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $data = $request->validate([
            'withdrawn_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ...$this->actionReasonRules('withdrawal'),
        ], [
            ...$this->actionReasonMessages(),
            'withdrawn_on.before_or_equal' => 'The withdrawal date cannot be in the future.',
        ], ['withdrawn_on' => 'withdrawal date']);

        try {
            $action->handle($notice, Carbon::parse($data['withdrawn_on']), $request->user(), $this->actionReason($request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $notice->fresh()->wasDelivered()
            ? 'Notice withdrawn. The employee sees it marked withdrawn.'
            : 'Notice withdrawn. It never reached the employee, so it leaves their list.');
    }

    // --- Employee self-service ----------------------------------------------

    public function selfIndex(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your account is not linked to an employee record.');
        }

        return Inertia::render('hrm/my-notices/index', [
            'notices' => ShowCauseNotice::where('employee_id', $employee->id)
                ->where(fn ($query) => $query->whereNull('withdrawn_at')->orWhereNotNull('notified_at'))
                ->orderByDesc('issued_at')->get()
                ->map(fn (ShowCauseNotice $notice) => $this->present($notice)),
        ]);
    }

    public function selfShow(Request $request, ShowCauseNotice $notice): Response
    {
        $employee = Employee::forUser($request->user());

        // 404 rather than 403: whether a notice id exists is nobody else's
        // business, the same rule the report registry follows.
        abort_unless($employee !== null && $notice->employee_id === $employee->id, 404);
        // A withdrawn notice that never reached them is not theirs to see.
        abort_if($notice->isWithdrawn() && ! $notice->wasDelivered(), 404);

        return $this->printable($notice, backUrl: route('hrm.my-notices.index'));
    }

    private function printable(ShowCauseNotice $notice, string $backUrl): Response
    {
        $notice->load(['employee:id,employee_code,first_name,last_name', 'issuer:id,name']);

        return Inertia::render('hrm/notices/show', [
            'notice' => $this->present($notice, withBody: true),
            'business_name' => Business::find(app(BusinessContext::class)->id())?->name,
            'back_url' => $backUrl,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShowCauseNotice $notice, bool $withBody = false): array
    {
        return [
            'id' => $notice->id,
            'employee' => $notice->employee?->fullName(),
            'employee_code' => $notice->employee?->employee_code,
            'subject' => $notice->subject,
            'body' => $withBody ? $notice->body : null,
            'context' => $notice->context,
            'issued_by' => $notice->issuer?->name,
            'issued_at' => $notice->issued_at->format('Y-m-d H:i'),
            'withdrawn_on' => $notice->withdrawn_on?->format('d/m/Y'),
            'withdraw_reason' => $notice->withdraw_reason,
        ];
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
