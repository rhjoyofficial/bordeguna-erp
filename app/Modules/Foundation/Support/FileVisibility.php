<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\DistributorInspection;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\ExpenseClaimLine;
use App\Modules\Hrm\Models\JobApplicant;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\SupplierBill;

/**
 * Which stored files a person may see, and what they are allowed to do to them.
 *
 * ⚠⚠ THIS CLASS IS THE WHOLE POINT OF THE FILE MANAGER, and the reason it was
 * worth building carefully rather than quickly. Every file in the system already
 * lives in ONE table: an employee's document, the evidence behind an attendance
 * correction, a payroll adjustment's proof, an expense claim receipt, a supplier
 * bill scan. A browser over that table with a single `files.view` permission
 * would hand anybody who could open it every payslip-adjacent document in the
 * business, in one screen, sorted by date. That is not a hypothetical: it is the
 * obvious way to build this page.
 *
 * So a file is visible only to somebody who could already open the record it
 * hangs on, and the mapping is written out by hand where it can be read and
 * argued with.
 *
 * ⚠⚠ THE DEFAULT IS CLOSED. A type absent from this map is visible ONLY to
 * organisation management. When a future module starts attaching files and
 * nobody remembers this file, its documents stay hidden rather than quietly
 * appearing on a screen that was never told about them.
 */
class FileVisibility
{
    /**
     * Attachable type → the permission that already guards that record.
     *
     * @var array<class-string,string>
     */
    private const REQUIRES = [
        // The logo and the shared library. Not sensitive, and the library is
        // what the file manager is FOR.
        Business::class => 'files.view',

        Lead::class => 'crm.leads.view',
        // ⚠ Photos from a distributor inspection (CRM slice 2c): the lead's files by
        // another route, so the lead's permission AND its row scope.
        DistributorInspection::class => 'crm.leads.view',
        // ⚠ Photos from a visit (CRM slice 2d). Row-scoped below: a visit on a
        // lead is found only where the lead is.
        CrmActivity::class => 'crm.leads.view',

        // ⚠ People's documents. Photographs, national id, contracts.
        Employee::class => 'hrm.employees.manage',
        AttendanceCorrection::class => 'hrm.attendance.manage',

        // ⚠⚠ A CV. Added the same day recruitment shipped, because the closed
        // default would otherwise have hidden every applicant's CV from the
        // people running the hiring round - the default failing SAFE, and still
        // failing. A new attachable type belongs in this map on the day it
        // starts attaching files.
        JobApplicant::class => 'recruitment.view',

        // ⚠⚠ Money paid to staff. The three that would hurt most in the wrong
        // hands, which is why the default below is closed rather than open.
        PayrollAdjustment::class => 'hrm.payroll.manage',
        DisbursementBatch::class => 'hrm.payroll.manage',
        ExpenseClaim::class => 'hrm.payroll.manage',

        // ⚠ The receipt moved from the claim to its LINE on 10 Sep 2026, when a
        // claim became a header with several spends under it. Without this row
        // the closed default would have hidden every expense receipt in the
        // file manager from the very people who approve and pay them.
        ExpenseClaimLine::class => 'hrm.payroll.manage',

        ExpenseVoucher::class => 'purchasing.expense.record',
        SupplierBill::class => 'purchasing.reports.view',
        InventoryAdjustment::class => 'inventory.reports.view',
    ];

    /**
     * ⚠⚠ Types where the permission above is NECESSARY BUT NOT SUFFICIENT,
     * because the records themselves are narrowed per reader by a global scope.
     * Since 15 Sep 2026 a lead is visible only to its owner, their team and the
     * Owner role; `crm.leads.view` alone would otherwise list every lead's files.
     * A type added here has its files listed only where its own model query can
     * still find the record.
     *
     * @var list<class-string>
     */
    private const ROW_SCOPED = [
        Lead::class,
        DistributorInspection::class,
        CrmActivity::class,
    ];

    /** The collection standalone files live in, on the Business itself. */
    public const LIBRARY = 'library';

    /**
     * The attachable types this reader may be shown.
     *
     * @return list<class-string>
     */
    public function typesVisibleTo(User $user): array
    {
        $allowed = [];

        foreach (self::REQUIRES as $type => $permission) {
            if ($user->can($permission)) {
                $allowed[] = $type;
            }
        }

        return $allowed;
    }

    /**
     * ⚠ Everything NOT in the map, for the one role allowed to see it.
     * Returned separately so the query can say "these types, or anything
     * unknown" only when the reader is entitled to the unknown.
     */
    public function maySeeUnmappedTypes(User $user): bool
    {
        return $user->can('foundation.organization.manage');
    }

    /**
     * @return list<class-string>
     */
    public function rowScopedTypes(): array
    {
        return self::ROW_SCOPED;
    }

    /**
     * @return list<class-string>
     */
    public function mappedTypes(): array
    {
        return array_keys(self::REQUIRES);
    }

    /**
     * ⚠⚠ ONLY A LIBRARY FILE MAY BE DELETED HERE, and never a file attached to a
     * record. An expense claim receipt, a payroll adjustment's proof and an
     * attendance correction's evidence are EVIDENCE, and a generic file browser
     * with a delete button is how evidence disappears without anybody deciding
     * that it should. Removing one of those stays with the module that owns it,
     * where the consequence is visible.
     */
    public function mayDelete(User $user, ?string $attachableType, ?string $collection): bool
    {
        return $user->can('files.manage')
            && $attachableType === Business::class
            && $collection === self::LIBRARY;
    }
}
