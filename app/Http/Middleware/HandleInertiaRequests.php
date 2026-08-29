<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Support\ApprovalInbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // W3. The business this page was BUILT for, which is not the same
            // thing as the business the session holds by the time somebody
            // clicks a button in it. The client sends it back on every mutating
            // request and EnsurePageBusinessMatches refuses a mismatch, the same
            // way a CSRF token makes a page assert the context it came from.
            'current_business_id' => fn () => app(BusinessContext::class)->id(),
            'pendingApprovalsCount' => fn () => $this->actionableApprovalsCount($request),
            'canReviewApprovals' => fn () => $this->canReviewApprovals($request),
            // The bell's own feed. In-app since 2026-08-23, when approval
            // emails were switched off on Joy's instruction, so this is now the
            // ONLY way an approver learns something is waiting.
            //
            // ⚠ Capped at five and shipped with a count, rather than the whole
            // unread list. A dropdown is not an inbox, and serialising two
            // hundred rows into every page load to render five of them is a
            // cost paid on every request in the application.
            'unreadNotificationsCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'recentNotifications' => fn () => $request->user()
                ?->unreadNotifications()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'summary' => $n->data['summary'] ?? 'Something needs your attention.',
                    'url' => $n->data['url'] ?? null,
                    'kind' => $n->data['kind'] ?? 'general',
                    'at' => $n->created_at?->diffForHumans(),
                ])
                ->values()
                ->all() ?? [],
            'canManageUsers' => fn () => (bool) $request->user()?->can('foundation.users.manage'),
            'canManageOrganization' => fn () => (bool) $request->user()?->can('foundation.organization.manage'),
            'canManageEmployees' => fn () => (bool) $request->user()?->can('hrm.employees.manage'),
            'canManageScheduling' => fn () => (bool) $request->user()?->can('hrm.scheduling.manage'),
            'canManageAttendance' => fn () => (bool) $request->user()?->can('hrm.attendance.manage'),
            'canManageLeave' => fn () => (bool) $request->user()?->can('hrm.leave.manage'),
            // Correction C5 / ACL-02: Payroll HR manage, Finance and authorised
            // Management read. Department Managers hold neither.
            'canAccessSalary' => fn () => (bool) ($request->user()?->can('hrm.salary.manage')
                || $request->user()?->can('hrm.salary.view')),
            // WFL-04: who may generate a payroll run.
            'canManagePayroll' => fn () => (bool) $request->user()?->can('hrm.payroll.manage'),
            // Accounting Phase 2. Reading the ledger is close to reading payroll
            // once salary journals exist, so it follows the same ACL-02 shape:
            // Owner, Finance and Payroll, nobody else by default.
            'canViewAccounting' => fn () => (bool) $request->user()?->can('accounting.view'),
            'canManageChartOfAccounts' => fn () => (bool) $request->user()?->can('accounting.chart.manage'),
            // C-GL-6: the highest-risk permission in the system. Owner only.
            'canPostJournal' => fn () => (bool) $request->user()?->can('accounting.journal.post'),
            // Money LEAVING the business for a supplier. Deliberately not
            // `purchasing.payment.update`, which only guards a payment-status
            // string on a purchase order and moves nothing.
            'canRecordSupplierPayment' => fn () => (bool) $request->user()?->can('purchasing.payment.record'),
            // ⚠⚠ SALES AND CRM WERE DEPLOYED WITH NO WAY TO REACH THEM. Every
            // Sales page - the counter, customers, prices, invoices, deliveries -
            // and both CRM pages were live in production and absent from the
            // navigation and from every other screen, so the only way in was to
            // type the URL. The revenue side of the ERP was invisible.
            // ⚠⚠ AND IT HAPPENED AGAIN, to the single most important screen of
            // the cutover. Opening stock had a route, a screen and, from
            // 602d600, the printable count sheet - and no navigation entry
            // anywhere, so the only way in was to type the URL. It is written
            // once per warehouse for the life of the business, and on 31 August
            // the people doing it are entering everything by hand.
            'canRecordOpeningStock' => fn () => (bool) $request->user()?->can('inventory.stock.adjust'),
            // C-IMP-7. A screen nobody can find is a screen nobody has, and
            // this project has shipped four of those.
            'canImportCatalogue' => fn () => (bool) $request->user()?->can('inventory.catalogue.import'),
            'canImportPrices' => fn () => (bool) $request->user()?->can('sales.prices.import'),
            // C-BOM-9. Same rule again, and this file is now the register of
            // how many times it has been needed.
            'canManageBom' => fn () => (bool) $request->user()?->can('inventory.bom.manage'),
            // ⚠ Inventory and Purchases were the last two ungated sidebar
            // groups: "anyone in the business can view" was written when
            // everyone in the business was trusted staff. The Self-service
            // role (24 Aug 2026, zero permissions) ended that assumption -
            // Joy's people saw the stock book and the supplier bills from an
            // account meant to reach only their own profile. Every real role
            // already holds these view permissions, so nothing changes for
            // anyone else.
            'canViewInventory' => fn () => (bool) $request->user()?->can('inventory.reports.view'),
            'canViewPurchasing' => fn () => (bool) $request->user()?->can('purchasing.reports.view'),
            'canViewSales' => fn () => (bool) $request->user()?->can('sales.reports.view'),
            'canSell' => fn () => (bool) $request->user()?->can('sales.sell'),
            'canManagePrices' => fn () => (bool) $request->user()?->can('sales.prices.manage'),
            'canViewCrm' => fn () => (bool) $request->user()?->can('crm.leads.view'),
            'canViewContacts' => fn () => (bool) $request->user()?->can('crm.contacts.view'),
            'canViewCalendar' => fn () => (bool) $request->user()?->can('calendar.view'),
            'canViewNotes' => fn () => (bool) $request->user()?->can('notes.view'),
            'canManageCrmSettings' => fn () => (bool) $request->user()?->can('crm.settings.manage'),
            // ⚠ The Assets module shipped loading MIGRATIONS ONLY - no routes, no
            // screens, no permissions - so nothing the business owns could be
            // recorded and depreciation had no caller at all. Reading the
            // register is its own permission rather than open to the business:
            // what the company owns and paid is closer to payroll than to stock.
            'canViewAssets' => fn () => (bool) $request->user()?->can('assets.view'),
            'canRunDepreciation' => fn () => (bool) $request->user()?->can('assets.depreciation.run'),
            // Self-scope (P3): whether the signed-in user has their own employee
            // record, so the "My profile" self-service link can show. Distinct
            // from canManageEmployees — an employee who isn't HR still has one.
            'hasEmployeeProfile' => fn () => $request->user() !== null
                && Employee::where('user_id', $request->user()->id)->exists(),
        ]);
    }

    private function canReviewApprovals(Request $request): bool
    {
        $user = $request->user();

        if (! $user || ! app(BusinessContext::class)->hasBusiness()) {
            return false;
        }

        // Legacy approvers keep the nav even at zero pending; anyone else sees it
        // once they actually have something to act on (a change-request stage).
        return $user->can('inventory.stock.approve')
            || $user->can('purchasing.po.approve')
            || $this->actionableApprovalsCount($request) > 0;
    }

    private function actionableApprovalsCount(Request $request): int
    {
        $user = $request->user();

        if (! $user || ! app(BusinessContext::class)->hasBusiness()) {
            return 0;
        }

        return app(ApprovalInbox::class)->countFor($user);
    }
}
