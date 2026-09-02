<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR letters (Module 5, Slice 2): the system drafts, the boss signs.
 *
 * ⚠ A letter is issued from LIVE data but kept as a SNAPSHOT in body_data -
 * a salary change next month must not silently rewrite an appointment
 * letter issued today. Print renders the snapshot, never the live record.
 */
class HrLetterController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        $data = $request->validate([
            'type' => ['required', Rule::in(['appointment', 'confirmation', 'increment', 'experience'])],
        ]);

        $snapshot = $this->snapshot($employee, $data['type']);

        // ⚠ Employees are a CENTRAL record with no business_id column - the
        // letter belongs to the business the issuer is working in.
        $businessId = app(BusinessContext::class)->id();

        DB::table('hr_letters')->insert([
            'business_id' => $businessId,
            'employee_id' => $employee->id,
            'reference_no' => $this->nextNumber($businessId),
            'type' => $data['type'],
            'issued_on' => Carbon::today()->toDateString(),
            'body_data' => json_encode($snapshot),
            'issued_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Letter drafted. Open it, print it, and the boss signs.');
    }

    public function print(int $letter): Response
    {
        $row = DB::table('hr_letters')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $letter)->first();
        abort_if($row === null, 404);

        return Inertia::render('hrm/letters/print', [
            'letter' => [
                'id' => $row->id,
                'reference_no' => $row->reference_no,
                'type' => $row->type,
                'issued_on' => $row->issued_on,
                'body_data' => json_decode($row->body_data, true),
            ],
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function index(Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');

        return Inertia::render('hrm/letters/index', [
            'employee' => $employee->only(['id', 'employee_code', 'first_name', 'last_name']),
            'letters' => DB::table('hr_letters')
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'reference_no' => $row->reference_no,
                    'type' => $row->type,
                    'issued_on' => $row->issued_on,
                    'issued_by' => DB::table('users')->where('id', $row->issued_by)->value('name'),
                ]),
        ]);
    }

    /**
     * The facts each letter states, gathered NOW and frozen.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Employee $employee, string $type): array
    {
        $assignment = $employee->currentAssignment();

        $base = [
            'employee_name' => trim($employee->first_name.' '.$employee->last_name),
            'employee_code' => $employee->employee_code,
            'designation' => $assignment?->designation?->name,
            'department' => $assignment?->department?->name,
            'join_date' => $employee->join_date?->toDateString(),
        ];

        return match ($type) {
            'appointment' => $base + [
                'gross_salary' => \App\Modules\Hrm\Models\SalaryStructure::activeFor($employee->id)?->breakdown()->gross,
                'employment_type' => $employee->employment_type?->value ?? (string) $employee->employment_type,
                'probation_months' => $employee->probation_months,
            ],
            'confirmation' => $base + [
                'confirmation_date' => $employee->confirmation_date?->toDateString()
                    ?? throw ValidationException::withMessages([
                        'type' => 'No confirmation date on record - confirm the employee first.',
                    ]),
            ],
            'increment' => $base + $this->incrementFacts($employee),
            'experience' => $base + [
                // A leaver's letter ends on the separation date; a current
                // employee's says so.
                'end_date' => $employee->separation_date?->toDateString(),
                'still_employed' => $employee->separation_date === null,
            ],
        };
    }

    /** @return array<string, mixed> */
    private function incrementFacts(Employee $employee): array
    {
        $revision = SalaryRevision::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('applied_at')
            ->orderByDesc('applied_at')
            ->first();

        if ($revision === null) {
            throw ValidationException::withMessages([
                'type' => 'No applied salary revision exists - an increment letter needs a real increment to describe.',
            ]);
        }

        return [
            'effective_from' => $revision->effective_from?->toDateString() ?? (string) $revision->effective_from,
            'new_gross' => \App\Modules\Hrm\Models\SalaryStructure::activeFor($employee->id)?->breakdown()->gross,
        ];
    }

    private function nextNumber(int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'LTR-'.FiscalYear::containing(Carbon::now(), $business)->label().'-';

        $latest = DB::table('hr_letters')
            ->where('reference_no', 'like', $prefix.'%')
            ->orderByDesc('reference_no')
            ->value('reference_no');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
