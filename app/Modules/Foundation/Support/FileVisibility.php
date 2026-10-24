<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
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
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCertification;

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
     * Attachable type → the permission that already guards that record. A list
     * means any one of them is enough.
     *
     * @var array<class-string,string|list<string>>
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

        // ⚠ The expenses screen is read with `purchasing.reports.view` and links
        // every receipt on it, so its readers must be able to open them too.
        ExpenseVoucher::class => ['purchasing.expense.record', 'purchasing.reports.view'],
        // ⭐ Purchase redesign PR1: a supplier quotation or Mushak 6.3 on an order.
        PurchaseOrder::class => ['purchasing.reports.view'],
        // A voucher's receipts open for whoever may read the books it posts to.
        Voucher::class => 'accounting.view',
        // ⚠ Other income (13 Oct 2026): unmapped until the stream started
        // asking this class, which would have hidden the evidence on the
        // other-income screen from the accountants who read it.
        IncomeVoucher::class => 'accounting.view',
        // ⚠ The Loan Register (14 Oct 2026): a loan's signed agreement and the
        // evidence behind a write-off or waiver, for whoever reads the register.
        LoanContract::class => 'accounting.loans.view',
        LoanWriteOff::class => 'accounting.loans.view',
        SupplierBill::class => 'purchasing.reports.view',
        // ⭐ Initial plan B1 (R58, 25 Oct 2026): a delivery's challan photo or
        // labour slip, for whoever reads the receipts.
        GoodsReceipt::class => 'purchasing.reports.view',
        // A supplier's certificate scans (answer 18) are shown to buyers on
        // purpose. The supplier's own NID scans are NOT mapped: see
        // HoldsIdentityDocuments.
        SupplierCertification::class => 'purchasing.reports.view',
        InventoryAdjustment::class => 'inventory.reports.view',
        // Photos of the things the business keeps (asset management slice A1,
        // 16 Sep 2026): for whoever keeps the register.
        AssetItem::class => 'assets.items.view',
        // Hand-over photos (slice A2), same readers.
        AssetMovement::class => 'assets.items.view',
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

    /** The business logo, drawn in the app header and on every printed page. */
    public const LOGO = 'logo';

    /**
     * The attachable types this reader may be shown.
     *
     * @return list<class-string>
     */
    public function typesVisibleTo(User $user): array
    {
        $allowed = [];

        foreach (self::REQUIRES as $type => $permission) {
            if ($user->canAny((array) $permission)) {
                $allowed[] = $type;
            }
        }

        return $allowed;
    }

    /**
     * Whether this reader may open ONE file, by id, through attachments.show.
     *
     * ⚠⚠ THE SAME RULE AS THE LIST (audit, 13 Oct 2026). The archive hid a
     * payroll bank advice from the self-service role while the stream handed
     * it to anybody who typed its id. A file the archive will not show is a
     * file the stream will not serve.
     *
     * ⚠ Owners that implement ControlsAttachmentAccess decide for themselves
     * and never reach here. That includes every ROW_SCOPED type, whose own rule
     * already knows which rows this reader may see.
     */
    public function mayOpen(User $user, ?string $attachableType, ?string $collection): bool
    {
        // The logo is on every page anybody prints. It is not a document.
        if ($attachableType === Business::class && $collection === self::LOGO) {
            return true;
        }

        if ($attachableType !== null && array_key_exists($attachableType, self::REQUIRES)) {
            return $user->canAny((array) self::REQUIRES[$attachableType]);
        }

        return $this->maySeeUnmappedTypes($user);
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
