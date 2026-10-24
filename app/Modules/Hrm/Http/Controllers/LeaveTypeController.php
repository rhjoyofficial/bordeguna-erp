<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The leave-type rules screen (SmartHR gap G19). The rules were data from the
 * start (Corrections C6b/C6c put them on the type as "HR-editable data, not
 * constants") - this controller is the screen that promise implied.
 *
 * A used type is switched off, never deleted: requests and ledger entries
 * keep the leave_type_id they were recorded with. ⭐ Initial plan B24 (Joy's
 * Q5 = A, 25 Oct 2026): a type NEVER used goes to the Recycle bin with a
 * reason (bin), and the Owner restores it; Switch off asks a reason too.
 *
 * `can_encash` is deliberately absent from both forms. Encashment was switched
 * off by policy on 23 Aug 2026 (no provision journal, no payout), and a toggle
 * here would reopen a settled question from a screen; the stored flags keep
 * their values for whenever that policy changes.
 */
class LeaveTypeController extends Controller
{
    use ValidatesActionReason;

    /**
     * Codes the engine addresses directly: `unpaid` is where LEV-07 sends a
     * request with no balance behind it, `compensatory` is where ADJ-04's
     * holiday-overtime credit lands. Deactivating either detaches a live
     * destination, so the toggle refuses.
     */
    private const SYSTEM_CODES = LeaveType::SYSTEM_CODES;

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('leave_types')->where('business_id', $this->businessId()),
            ],
            ...$this->ruleFields(),
        ], [
            'code.regex' => 'The code must be lowercase letters, digits and underscores, starting with a letter.',
            'code.unique' => LeaveType::onlyTrashed()->where('code', (string) $request->input('code'))->exists()
                ? 'A leave type with this code is in the Recycle bin. Ask the Owner to restore it.'
                : 'This code is already taken.',
        ]);

        LeaveType::create($this->normaliseCap($data));

        return back()->with('success', 'Leave type created.');
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        // No `code` here: payroll and LEV-07 address types by code (see the
        // COMPENSATORY constant), so a rename would silently detach them.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            ...$this->ruleFields(),
        ]);

        $leaveType->update($this->normaliseCap($data));

        return back()->with('success', 'Leave type updated.');
    }

    public function toggleActive(LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        if ($leaveType->is_active && in_array($leaveType->code, self::SYSTEM_CODES, true)) {
            return back()->with('error', 'This type is a system destination (unpaid fallback / holiday-overtime credit) and cannot be deactivated.');
        }

        $leaveType->update(['is_active' => ! $leaveType->is_active]);

        return back()->with('success', $leaveType->is_active ? 'Leave type reactivated.' : 'Leave type deactivated.');
    }

    /**
     * ⭐ B24: Switch off with a reason (Reactivate stays the plain toggle).
     */
    public function switchOff(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');
        $request->validate($this->actionReasonRules('deactivation'), $this->actionReasonMessages());

        if (($why = $leaveType->whyNotDeactivatable()) !== null) {
            throw ValidationException::withMessages(['reason_text' => $why]);
        }

        $leaveType->update(['is_active' => false]);
        CorrectionLog::event('deactivated', $request->user(), $leaveType, ['name' => $leaveType->name, 'reason' => $this->actionReason($request)->display()]);

        return back()->with('success', "{$leaveType->name} switched off. Its history stays.");
    }

    /**
     * ⭐ B24: Delete to the Recycle bin, only when never used
     * (LeaveType::whyNotRemovable), with a reason of 5 characters at least.
     */
    public function bin(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        DB::transaction(fn () => LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail()
            ->recycle($request->user(), $this->actionReason($request)));

        return back()->with('success', "{$leaveType->name} moved to the Recycle bin. The Owner can restore it.");
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function ruleFields(): array
    {
        return [
            'annual_quota' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'is_paid' => ['required', 'boolean'],
            'deducts_balance' => ['required', 'boolean'],
            'allows_half_day' => ['required', 'boolean'],
            'can_carry_forward' => ['required', 'boolean'],
            'carry_forward_cap' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }

    /**
     * A cap on a type that cannot carry forward is a number waiting to confuse
     * someone; store null so the screen and the engine agree.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseCap(array $data): array
    {
        if (! $data['can_carry_forward']) {
            $data['carry_forward_cap'] = null;
        }

        return $data;
    }
}
