<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Models\AttendancePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attendance policy screen - POL-03's ladders as data HR can adjust
 * (Joy, 24 Aug 2026: "make them dynamic so from settings this can be adjust").
 *
 * ⚠⚠ Editing NEVER touches the row that judged existing days. Saving a change
 * closes the current row the day before the new effective date and opens a new
 * row at version+1 - the same POL-12 shape the engine already resolves per
 * date, so a finalised day keeps the exact version that judged it and "which
 * policy decided this" stays answerable from policy_id alone, years later.
 */
class AttendancePolicyController extends Controller
{
    public function index(): Response
    {
        $this->authorize('hrm.attendance.manage');

        $policies = AttendancePolicy::with('department:id,name')
            ->orderByRaw('department_id is not null')
            ->orderBy('department_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->get();

        return Inertia::render('hrm/policies/index', [
            'scopes' => $policies->groupBy(fn (AttendancePolicy $p) => $p->department_id ?? 0)
                ->map(fn ($rows) => [
                    'department_id' => $rows->first()->department_id,
                    'department' => $rows->first()->department?->name,
                    // The open-ended row is the one that can be revised; a scope
                    // with only closed rows has no current policy and says so.
                    'current' => $this->present($rows->firstWhere('effective_to', null)),
                    'history' => $rows->filter(fn ($p) => $p->effective_to !== null)
                        ->map(fn ($p) => [
                            'version' => $p->version,
                            'effective_from' => $p->effective_from->toDateString(),
                            'effective_to' => $p->effective_to->toDateString(),
                        ])->values(),
                ])->values(),
            'consequences' => collect(AttendanceConsequence::cases())
                ->map(fn (AttendanceConsequence $c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?AttendancePolicy $policy): ?array
    {
        if ($policy === null) {
            return null;
        }

        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'version' => $policy->version,
            'effective_from' => $policy->effective_from->toDateString(),
            // Normalised through the engine's own value object, so the form
            // edits exactly what the engine reads - defaults included.
            'config' => PolicyConfig::fromArray($policy->config ?? [])->toArray(),
        ];
    }

    public function revise(Request $request, AttendancePolicy $policy): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        if ($policy->effective_to !== null) {
            return back()->with('error', 'Only the current version can be revised - this row is history.');
        }

        $data = $request->validate([
            'effective_from' => [
                'required', 'date',
                // Strictly after the current version's start: the old row is
                // closed the day before, and a row must not close before it
                // opened.
                'after:'.$policy->effective_from->toDateString(),
            ],
            'early_leave_grace_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'half_day_maximum_hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'full_day_minimum_hours' => ['required', 'numeric', 'gt:half_day_maximum_hours', 'max:24'],
            'half_day_arrival_after_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'count_holiday_work_without_approval' => ['required', 'boolean'],
            'duplicate_scan_window_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'grace_windows' => ['required', 'array', 'min:1'],
            'grace_windows.*.key' => ['nullable', 'string', 'max:60'],
            'grace_windows.*.label' => ['required', 'string', 'max:100'],
            'grace_windows.*.from_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'grace_windows.*.to_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'grace_windows.*.requires_review' => ['required', 'boolean'],
            'grace_windows.*.tiers' => ['required', 'array', 'min:1'],
            'grace_windows.*.tiers.*.from_occurrence' => ['required', 'integer', 'min:1', 'max:60'],
            'grace_windows.*.tiers.*.consequence' => ['required', new EnumRule(AttendanceConsequence::class)],
            // The earned-leave accrual rule (Joy, 24 Aug 2026). Optional in the
            // request: only the company-default card offers these fields, and a
            // department revision must not silently blank them.
            'el_accrual' => ['sometimes', 'array'],
            'el_accrual.enabled' => ['required_with:el_accrual', 'boolean'],
            'el_accrual.days_per_month' => ['required_with:el_accrual', 'numeric', 'gt:0', 'max:31'],
            'el_accrual.max_ordinary_lates' => ['required_with:el_accrual', 'integer', 'min:0', 'max:60'],
            'el_accrual.max_serious_lates' => ['required_with:el_accrual', 'integer', 'min:0', 'max:60'],
        ], [
            'effective_from.after' => 'The new version must start after the current one ('.$policy->effective_from->toDateString().').',
            'full_day_minimum_hours.gt' => 'A full day must require more hours than a half day allows.',
        ]);

        $windows = $this->guardWindows($data['grace_windows']);

        $config = PolicyConfig::fromArray([
            'grace_windows' => $windows,
            'early_leave_grace_minutes' => $data['early_leave_grace_minutes'],
            'half_day_maximum_hours' => $data['half_day_maximum_hours'],
            'full_day_minimum_hours' => $data['full_day_minimum_hours'],
            'half_day_arrival_after_minutes' => $data['half_day_arrival_after_minutes'] ?? null,
            'count_holiday_work_without_approval' => $data['count_holiday_work_without_approval'],
            'duplicate_scan_window_seconds' => $data['duplicate_scan_window_seconds'],
            // ⚠ Carried forward when the form did not send it. A department
            // policy's revision dialog has no accrual fields, and a revision
            // that dropped the rule would switch the accrual off for the whole
            // business by omission.
            'el_accrual' => $data['el_accrual'] ?? $policy->toConfig()->elAccrual->toArray(),
        ])->toArray();

        DB::transaction(function () use ($policy, $data, $config, $request) {
            $from = Carbon::parse($data['effective_from']);

            $policy->update(['effective_to' => $from->copy()->subDay()->toDateString()]);

            AttendancePolicy::create([
                'department_id' => $policy->department_id,
                'name' => $policy->name,
                'version' => $policy->version + 1,
                'config' => $config,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', sprintf(
            'Version %d takes effect from %s. Days already judged keep version %d.',
            $policy->version + 1, $data['effective_from'], $policy->version,
        ));
    }

    /**
     * The structural rules a per-field validator cannot see: window order,
     * overlap, the open-ended tail, and each ladder's shape.
     *
     * @param  array<int, array<string, mixed>>  $windows
     * @return array<int, array<string, mixed>> normalised windows, sorted
     *
     * @throws ValidationException
     */
    private function guardWindows(array $windows): array
    {
        usort($windows, fn ($a, $b) => (int) $a['from_minutes'] <=> (int) $b['from_minutes']);

        $seenKeys = [];
        $previous = null;

        foreach ($windows as $index => &$window) {
            $from = (int) $window['from_minutes'];
            $to = $window['to_minutes'] !== null && $window['to_minutes'] !== '' ? (int) $window['to_minutes'] : null;

            if ($to !== null && $to <= $from) {
                throw ValidationException::withMessages([
                    'grace_windows' => sprintf('"%s": the upper bound must be greater than the lower bound.', $window['label']),
                ]);
            }

            if ($previous !== null) {
                if ($previous['to_minutes'] === null) {
                    throw ValidationException::withMessages([
                        'grace_windows' => 'Only the last window may be open-ended.',
                    ]);
                }
                // Bounds are exclusive-inclusive, so a window may START exactly
                // where the previous one ends - that is contiguity, not overlap.
                if ($from < (int) $previous['to_minutes']) {
                    throw ValidationException::withMessages([
                        'grace_windows' => sprintf('"%s" overlaps "%s".', $window['label'], $previous['label']),
                    ]);
                }
            }

            // A stable key is what the monthly occurrence counter groups by, so
            // an existing window keeps the key it came with; only a new window
            // gets one derived from its label.
            $key = filled($window['key'] ?? null) ? $window['key'] : Str::slug($window['label'], '_');

            if (in_array($key, $seenKeys, true)) {
                $key .= '_'.($index + 1);
            }
            $seenKeys[] = $key;
            $window['key'] = $key;

            $tiers = $window['tiers'];
            usort($tiers, fn ($a, $b) => (int) $a['from_occurrence'] <=> (int) $b['from_occurrence']);

            if ((int) $tiers[0]['from_occurrence'] !== 1) {
                throw ValidationException::withMessages([
                    'grace_windows' => sprintf('"%s": the ladder must start at the 1st occurrence, so every arrival has a stated consequence.', $window['label']),
                ]);
            }

            foreach ($tiers as $i => $tier) {
                if ($i > 0 && (int) $tier['from_occurrence'] <= (int) $tiers[$i - 1]['from_occurrence']) {
                    throw ValidationException::withMessages([
                        'grace_windows' => sprintf('"%s": tier occurrences must strictly increase.', $window['label']),
                    ]);
                }
            }

            $window['tiers'] = $tiers;
            $previous = ['to_minutes' => $to, 'label' => $window['label']];
        }

        return $windows;
    }
}
