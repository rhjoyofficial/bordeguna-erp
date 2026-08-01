<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\SalaryStructureLine;
use Illuminate\Database\Seeder;

/**
 * W1 migration, part one: Bor De Guna's nine real employees.
 *
 * Source: `D:\WORK\BorDeGuna\ORG-STRUCTURE-SPEC.md` §6 and §7, which is itself
 * derived from the employee list the client supplied on 2026-07-28. Every
 * figure here traces to that table; nothing is invented.
 *
 * ⚠⚠ **SALARY IS ONE AMOUNT — THE WHOLE GROSS BECOMES BASIC.** Settled with Joy
 * 2026-07-28. Bor De Guna does not split salary into House Rent / Medical /
 * Conveyance, so a structure here is a single Basic line. This matters beyond
 * bookkeeping: overtime is `Basic ÷ 30 ÷ 8`, and absence is deducted from
 * Gross, so inventing a 50/30/20 split would silently change both.
 *
 * ⚠ **Assignments are effective-dated from the JOINING DATE, not from go-live.**
 * Service history and any future transfer only read correctly if the timeline
 * starts where it really started — two of these people have seven years.
 *
 * ⚠ **Idempotent, like every seeder in this project.** Employees are matched on
 * their permanent code and structures on their effective date, so a re-run
 * updates rather than duplicating. That is what makes it safe to correct one
 * row and run it again.
 *
 * ---
 *
 * ⚠⚠ **WHAT IS DELIBERATELY ABSENT, AND WHAT IT COSTS**
 *
 * The client has not yet supplied these, and they are **not guessed**:
 *
 * - **Bank / MFS details.** WFL-03 refuses to calculate a payroll for an
 *   employee with no payment method, so **payroll will block until these are
 *   entered**. That is the correct behaviour, not a defect.
 * - **Date of birth.** C9b derives the payslip PDF password from employee code
 *   + DOB, so a protected payslip is **refused** for anyone without one. The
 *   portal view and the register are unaffected, so nobody is unpaid.
 * - **Employment type.** ✅ **CONFIRMED by Joy 2026-07-29: every current
 *   employee is `permanent` unless he explicitly changes an individual.** This
 *   is now a standing policy rather than a placeholder, so the seeder asserts
 *   it rather than apologising for it.
 * - **Leave and loan opening balances.** The hardest item on the checklist, and
 *   the one that is wrong forever if wrong at go-live. They arrive as ledger
 *   entries in W1 part two, never as stored totals.
 */
class BorDeGunaEmployeeSeeder extends Seeder
{
    private const SLUG = 'bor-de-guna';

    /**
     * The nine, verbatim from ORG-STRUCTURE-SPEC §6.
     *
     * `supervisor` is the supervisor's employee code; §7 confirmed all nine
     * reporting lines on 2026-07-28, including the two Office Assistants
     * reporting to the Coordinator.
     *
     * `ta_da` is the per-day rate for the two Retail Sales Officers
     * (Correction C14). ⚠ It is NOT part of gross and must never be added to it.
     *
     * ✅ `enroll` is the ZKTeco `No.` column, SUPPLIED 2026-08-01 and read off
     * the device's own `AllInOutData` export rather than assumed. Every mapping
     * below is confirmed twice: the export carries both `No.` and a `CardNo`
     * holding the employee code, and the two agree on all eight.
     *
     * ⚠ `No.` is NOT the employee code and is not derivable from it — 7 maps to
     * BDG-000001, 8 to BDG-000010. The earlier note that the device held 12
     * enrolments against 9 employees is consistent with what arrived: only eight
     * enrolments produced punches, and 11 is absent from the export entirely.
     *
     * ⚠⚠ BDG-000002 (Shuborna Mostafa) HAS NO ENROLMENT AND IS NOT EXPECTED TO.
     * Joy confirmed 2026-08-01 that she works from home and gives no device
     * attendance. Her `enroll` is deliberately absent rather than blank-by-
     * oversight, and her presence is authorised by approved movement instead.
     * ⚠ There is no "exempt from attendance" concept in the model yet, so she
     * would otherwise be proposed Absent every working day — which blocks
     * payroll, since `is_provisional` is payroll's gate.
     */
    private const EMPLOYEES = [
        // ⚠ 'Chowdhury Mohammad', not 'Chowdhury Md.' — the name was corrected in
        // the app after the first seeding, and this seeder fills names on every
        // run. Left stale it silently reverts a correction a human made, which
        // is the kind of change nobody thinks to look for.
        ['code' => 'BDG-000001', 'first' => 'Chowdhury Mohammad', 'last' => 'Moin',
            'dept' => 'Main Team', 'designation' => 'Founder',
            'joined' => '2019-05-01', 'gross' => 100000, 'supervisor' => null, 'enroll' => '7'],

        ['code' => 'BDG-000002', 'first' => 'Shuborna', 'last' => 'Mostafa',
            'dept' => 'Main Team', 'designation' => 'Deputy Managing Director',
            // ⚠ 10,000 is lower than an assistant who joined in 2026. Joy
            // confirmed 2026-07-28 that this is CORRECT — benefits are received
            // elsewhere, confidentially. Do not flag it again.
            'joined' => '2019-05-01', 'gross' => 10000, 'supervisor' => 'BDG-000001'],

        ['code' => 'BDG-000012', 'first' => 'Musa', 'last' => 'Islam',
            'dept' => 'Main Team', 'designation' => 'Coordinator',
            'joined' => '2025-09-01', 'gross' => 18000, 'supervisor' => 'BDG-000002', 'enroll' => '9'],

        ['code' => 'BDG-000016', 'first' => 'Rakibul Hasan', 'last' => 'Joy',
            'dept' => 'Main Team', 'designation' => 'Software Innovation and System Engineer',
            'joined' => '2025-10-04', 'gross' => 30000, 'supervisor' => 'BDG-000012', 'enroll' => '10'],

        ['code' => 'BDG-000019', 'first' => 'Md. Shoykod', 'last' => 'Hossain',
            'dept' => 'Retail Sales Team', 'designation' => 'Assistant Sales Manager',
            'joined' => '2026-05-09', 'gross' => 35000, 'supervisor' => 'BDG-000012', 'enroll' => '12'],

        ['code' => 'BDG-000020', 'first' => 'Md. Rakib', 'last' => 'Hossain',
            'dept' => 'Retail Sales Team', 'designation' => 'Retail Sales Officer',
            'joined' => '2026-07-01', 'gross' => 13000, 'supervisor' => 'BDG-000019', 'ta_da' => 246, 'enroll' => '13'],

        ['code' => 'BDG-000021', 'first' => 'Md. Nazmul', 'last' => 'Haque',
            'dept' => 'Retail Sales Team', 'designation' => 'Retail Sales Officer',
            'joined' => '2026-07-01', 'gross' => 14000, 'supervisor' => 'BDG-000019', 'ta_da' => 361, 'enroll' => '14'],

        ['code' => 'BDG-000010', 'first' => 'Md. Iftekharul', 'last' => 'Alam',
            'dept' => 'Office Assistant Team', 'designation' => 'Operation Support Assistant',
            // ⚠ 3,000 also confirmed correct by Joy 2026-07-28. Do not flag.
            'joined' => '2026-07-21', 'gross' => 3000, 'supervisor' => 'BDG-000012', 'enroll' => '8'],

        ['code' => 'BDG-000022', 'first' => 'Md. Jahidul Islam', 'last' => 'Munna',
            'dept' => 'Office Assistant Team', 'designation' => 'Operation Support Assistant',
            'joined' => '2026-07-09', 'gross' => 12000, 'supervisor' => 'BDG-000012', 'enroll' => '15'],
    ];

    public function run(): void
    {
        $business = Business::where('slug', self::SLUG)->first();

        if ($business === null) {
            $this->command?->warn('Bor De Guna business not found — run BorDeGunaOrganisationSeeder first.');

            return;
        }

        app(BusinessContext::class)->set($business->id);

        $office = Office::where('business_id', $business->id)->orderBy('id')->first();
        $departments = Department::where('business_id', $business->id)->get()->keyBy('name');
        $designations = Designation::where('business_id', $business->id)->get()->keyBy('name');
        $basic = SalaryComponent::where('business_id', $business->id)->where('code', 'basic')->first();
        $taDa = SalaryComponent::where('business_id', $business->id)->where('code', 'ta_da')->first();

        // Pass one: the people. Supervisors are wired in pass two, because a
        // supervisor must exist before anyone can point at them.
        foreach (self::EMPLOYEES as $row) {
            $employee = Employee::withoutGlobalScopes()->firstOrNew(['employee_code' => $row['code']]);

            $employee->fill([
                'first_name' => $row['first'],
                'last_name' => $row['last'],
                // Confirmed standing policy (Joy, 2026-07-29).
                'employment_type' => 'permanent',
                'status' => 'active',
                'joining_date' => $row['joined'],
            ]);

            // ⚠⚠ THE FIELD THE ATTENDANCE BACKFILL MATCHES ON, and the single
            // most failure-prone item in the whole migration. `enroll` is the
            // ZKTeco device's `No.` column; if it does not match the device,
            // that person's punches import as unmatched rows FOREVER — not as
            // an error, just as a silently growing exception report.
            //
            // Deliberately only written when supplied, so a re-run cannot blank
            // an ID somebody has since entered by hand on the employee screen.
            if (($row['enroll'] ?? null) !== null) {
                $employee->biometric_enroll_id = (string) $row['enroll'];
            }

            $employee->save();

            $this->command?->line(sprintf('  %s  %s %s', $row['code'], $row['first'], $row['last']));
        }

        // Pass two: placements and reporting lines.
        foreach (self::EMPLOYEES as $row) {
            $employee = Employee::withoutGlobalScopes()->where('employee_code', $row['code'])->sole();
            $supervisor = $row['supervisor'] === null
                ? null
                : Employee::withoutGlobalScopes()->where('employee_code', $row['supervisor'])->first();

            // ⚠ whereDate, not a plain equality match: `effective_from` is a
            // date cast stored as "Y-m-d 00:00:00", so comparing it to "Y-m-d"
            // silently misses and the row is created again on every run. This
            // exact trap has now bitten this project five times.
            $assignment = EmployeeAssignment::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_from', $row['joined'])
                ->first();

            $attributes = [
                'business_id' => $business->id,
                'employee_id' => $employee->id,
                'office_id' => $office?->id,
                'department_id' => $departments->get($row['dept'])?->id,
                'designation_id' => $designations->get($row['designation'])?->id,
                'supervisor_id' => $supervisor?->id,
                'is_primary' => true,
                'effective_from' => $row['joined'],
            ];

            $assignment === null
                ? EmployeeAssignment::create($attributes)
                : $assignment->update($attributes);

            $this->salaryFor($employee, $row, $basic, $taDa);
        }

        $this->command?->info(sprintf('%d employees migrated for %s.', count(self::EMPLOYEES), $business->name));
        $this->command?->warn('⚠ Bank/MFS details and dates of birth are NOT set — payroll will block until they are.');
    }

    /**
     * One structure, effective from the joining date.
     *
     * ⚠ A single Basic line carrying the whole gross. See the class docblock:
     * this is Bor De Guna's actual arrangement, and splitting it would change
     * both the overtime rate and the absence deduction.
     */
    private function salaryFor(Employee $employee, array $row, ?SalaryComponent $basic, ?SalaryComponent $taDa): void
    {
        if ($basic === null) {
            return;
        }

        $structure = SalaryStructure::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', $row['joined'])
            ->first();

        if ($structure === null) {
            $structure = SalaryStructure::create([
                'employee_id' => $employee->id,
                'effective_from' => $row['joined'],
                'reason' => 'Migrated from the client employee list (W1).',
                // ⚠ `overtime_eligible` is deliberately left at its column
                // default. ADJ-01 / Correction C8d makes it a per-structure,
                // effective-dated flag, and WHO MAY EARN OVERTIME is a business
                // decision nobody has stated for these nine. Setting it true
                // here would quietly authorise overtime pay for the entire
                // company; setting it false could withhold pay somebody is owed.
                // It is one edit per employee on the salary screen once the
                // client answers.
            ]);
        }

        $this->line($structure, $basic->id, (float) $row['gross']);

        // Correction C14 — the per-day allowance for the two Retail Sales
        // Officers. ⚠ The stored value is a DAILY RATE, not a monthly amount,
        // and it is excluded from gross by `SalaryResolver`.
        if ($taDa !== null && isset($row['ta_da'])) {
            $this->line($structure, $taDa->id, (float) $row['ta_da']);
        }
    }

    private function line(SalaryStructure $structure, int $componentId, float $value): void
    {
        $line = SalaryStructureLine::withoutGlobalScopes()
            ->where('salary_structure_id', $structure->id)
            ->where('salary_component_id', $componentId)
            ->first();

        $line === null
            ? SalaryStructureLine::create([
                'salary_structure_id' => $structure->id,
                'salary_component_id' => $componentId,
                'value' => $value,
            ])
            : $line->update(['value' => $value]);
    }
}
