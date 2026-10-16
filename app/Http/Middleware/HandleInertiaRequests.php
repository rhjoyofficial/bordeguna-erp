<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Support\Ai\AssistantIdentity;
use App\Modules\Foundation\Support\Ai\AssistantLanguages;
use App\Modules\Foundation\Support\AssistantAvailability;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessesWithPermission;
use App\Modules\Foundation\Support\DocumentPdf;
use App\Modules\Foundation\Support\ErpAssistant;
use App\Modules\Hrm\Models\Employee;
use App\Support\ApprovalInbox;
use App\Support\Attention\NeedsAttention;
use App\Support\FailedJobs;
use App\Support\Popups\PopupFeed;
use App\Support\Preferences\BismillahGreeting;
use App\Support\Realtime\LivePages;
use App\Support\Schedule\ScheduleFeed;
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
                // ⚠ `avatar` was a typed field on the front end that NOTHING set
                // (found 30 Aug 2026: every avatar showed initials). The user
                // row has no picture column; the picture is the linked
                // employee's HR photo, resolved here so every page gets it.
                'user' => fn () => $request->user() === null
                    ? null
                    : array_merge($request->user()->toArray(), ['avatar' => $request->user()->avatarUrl()]),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // ⚠⚠ Dead until 21 Sep 2026. DiscountController flashed
                // "saved, but another rule outranks it" as `warning`, and only
                // the two keys above were ever shared, so the one message that
                // says a discount will NOT apply reached nobody (the dead-engine
                // family again: a flashed key is not a prop until it is here).
                'warning' => fn () => $request->session()->get('warning'),
            ],

            // ⚠⚠ FLASHED PREVIEWS. Found dead on 30 August 2026, and this is
            // dead engine #9 in the same family as #7 and #8.
            //
            // Three controllers answered a dry-run with `back()->with('...')`
            // and three React pages read `usePage().props.<key>` - and NOTHING
            // JOINED THE TWO. A flashed session key is not an Inertia prop; only
            // what is shared here or passed to Inertia::render ever reaches a
            // page. So every preview came back undefined and the panel silently
            // did not render.
            //
            // ⚠⚠ THE TESTS WERE ALL GREEN because they asserted
            // `assertSessionHas(...)` - the half that worked. That is the exact
            // failure the reachability rule keeps catching: a test anchored to
            // the server's own bookkeeping instead of to what the screen is
            // handed. ⛔ Assert the PROP.
            //
            // What was invisible matters: `contactImportPlan` is Joy's standing
            // requirement that an importer must preview before it applies, and
            // `campaignPreview` is the FIRST of the three brakes on a module
            // that spends money by itself. Both were built, tested and unseen.
            'contactImportPlan' => fn () => $request->session()->get('contactImportPlan'),
            'campaignPreview' => fn () => $request->session()->get('campaignPreview'),
            'test_result' => fn () => $request->session()->get('test_result'),
            // W3. The business this page was BUILT for, which is not the same
            // thing as the business the session holds by the time somebody
            // clicks a button in it. The client sends it back on every mutating
            // request and EnsurePageBusinessMatches refuses a mismatch, the same
            // way a CSRF token makes a page assert the context it came from.
            'current_business_id' => fn () => app(BusinessContext::class)->id(),
            // Realtime everywhere (11 Oct 2026): the channels this tab listens
            // on, and the record kinds this page shows (LivePages, derived from
            // the route; a controller's own `live` prop wins). Channel names
            // only, never data: every event is kind + id and the page re-reads.
            'realtime' => fn () => $this->realtime($request),
            'live' => fn () => LivePages::forRequest($request),
            // The Bismillah greeting (11 Oct 2026): the person's own switch and
            // the sign-in nonce. Null on guest pages, so it never shows there.
            'bismillah' => fn () => BismillahGreeting::share($request),
            // The popup engine (14 Oct 2026): the person's switches, the sound
            // each event plays, and the popups that apply to them today. Null
            // on guest pages. Deterministic per person per day, so the live
            // refresh never fires on it.
            'popups' => fn () => PopupFeed::share($request),
            // The work schedule alerts (14 Oct 2026): the switches, the server
            // clock and every active slot. Null on guest pages.
            'schedule' => fn () => ScheduleFeed::share($request),
            'pendingApprovalsCount' => fn () => $this->actionableApprovalsCount($request),
            'canReviewApprovals' => fn () => $this->canReviewApprovals($request),
            // Needs attention's sidebar badge (plan C.4): at most a minute old
            // (NeedsAttention::BADGE_SECONDS), refreshed whenever the page is read.
            'attentionCount' => fn () => $this->attentionCount($request),
            // The bell's own feed. In-app since 2026-08-23, when approval
            // emails were switched off on Joy's instruction, so this is now the
            // ONLY way an approver learns something is waiting.
            //
            // ⚠ Capped at eight and shipped with a count, rather than the whole
            // unread list. A dropdown is not an inbox, and serialising two
            // hundred rows into every page load to render five of them is a
            // cost paid on every request in the application.
            'unreadNotificationsCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'recentNotifications' => fn () => $request->user()
                ?->unreadNotifications()
                ->latest()
                ->limit(8)
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
            // The "New ledger head" button inside every ledger picker.
            'canManageChart' => fn () => (bool) $request->user()?->can('accounting.chart.head.create'),
            'canManageUsers' => fn () => (bool) $request->user()?->can('foundation.users.manage'),
            // Finance policy link (29 Sep 2026): shown only to whoever may open it.
            'canManageFinancePolicy' => fn () => (bool) $request->user()?->can('accounting.policy.manage'),
            // ACC-005 (14 Oct 2026): the VAT settings link, shown only to whoever
            // may open it. Finance and Payroll were shown it and met a 403.
            'canManageVat' => fn () => (bool) $request->user()?->can('accounting.vat.manage'),
            // ⚠⚠ TWO CONDITIONS, BOTH REQUIRED (Joy, 30 Aug 2026): the business
            // has the assistant switched on AND this person holds the
            // permission. Off, and the floating button is not rendered for
            // anybody; on, and it appears only for those granted it.
            //
            // ⚠ This prop hides a BUTTON. It is not the security boundary -
            // AssistantController authorizes the same permission, because a
            // hidden button is still a URL anybody can POST to, and every
            // message spends money.
            // ⚠ Built from what this person may actually see, so nobody is
            // offered a question that would only be refused. Resolved lazily -
            // it costs a permission check per page and only the assistant reads
            // it.
            'assistantSuggestions' => fn () => $request->user() !== null
                && $request->user()->can('foundation.ai.use')
                && AssistantAvailability::enabledForCurrentBusiness()
                    ? app(ErpAssistant::class)->suggestions($request->user())
                    : [],
            // The two languages this business has switched on, for the chat
            // toggle. Always two - AssistantLanguages guarantees it.
            'assistantLanguages' => fn () => AssistantLanguages::toggleFor(),
            // ⚠ Name, face and input ceiling, set per business in Settings. All
            // three are presentation and one limit; none of them widens what the
            // assistant may read, so this prop is safe to send to every page.
            'assistantIdentity' => fn () => AssistantIdentity::current(),
            'canUseAssistant' => fn () => $request->user() !== null
                && $request->user()->can('foundation.ai.use')
                && AssistantAvailability::enabledForCurrentBusiness(),
            // ⚠⚠ The Reasons settings screen authorizes `inventory.catalog.manage`,
            // NOT an organisation permission. The old settings nav showed the
            // link to everybody, so anybody without it followed a link the
            // interface offered them into a 403. Named after the screen rather
            // than the permission so the two cannot drift apart unnoticed.
            'canManageReasons' => fn () => (bool) $request->user()?->can('inventory.catalog.manage'),
            'canManageOrganization' => fn () => (bool) $request->user()?->can('foundation.organization.manage'),
            // ⚠ NOT a permission - a deployment fact. PDF export needs a
            // browser installed in the image, and where there is none the
            // Download PDF button is not offered at all rather than offered
            // and failing. Memoised per process, so this is not a filesystem
            // lookup on every page in the application.
            'pdfExport' => fn () => app(DocumentPdf::class)->isAvailable(),
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
            // Slice 0, D3: the group cockpit shows a business only to somebody who
            // reads ITS books, so the link shows when that is true of any one of
            // the user's businesses (roles are per business, hence the walk).
            'canViewGroupCockpit' => fn () => $request->user() !== null
                && BusinessesWithPermission::any($request->user(), 'accounting.view'),
            // Same walk for the other two group screens (audit, 13 Oct 2026):
            // partners read sales or purchasing balances, products read stock.
            'canViewGroupPartners' => fn () => $request->user() !== null
                && (BusinessesWithPermission::any($request->user(), 'sales.reports.view')
                    || BusinessesWithPermission::any($request->user(), 'purchasing.reports.view')),
            'canViewGroupProducts' => fn () => $request->user() !== null
                && BusinessesWithPermission::any($request->user(), 'inventory.reports.view'),
            // ⭐ 26 Sep 2026 (Q19): the three operating reports (receivables ageing,
            // customer statement, stock value) for the Manager, who does not
            // read the ledger. The gate is composed, so accounting readers pass too.
            'canViewOperatingReports' => fn () => (bool) $request->user()?->can('accounting.operations.view'),
            // Accounting > Counterparties (loans plan v2, 14 Oct 2026): the
            // Manager reads it without the ledger; Owner and Finance keep it.
            'canViewCounterparties' => fn () => (bool) $request->user()?->can('accounting.counterparties.view'),
            'canManageCounterparties' => fn () => (bool) $request->user()?->can('accounting.counterparties.manage'),
            // The Loan Register (loans plan v2, phase 2): Owner, Finance and Manager read it.
            'canViewLoans' => fn () => (bool) $request->user()?->can('accounting.loans.view'),
            'canManageLoans' => fn () => (bool) $request->user()?->can('accounting.loans.manage'),
            // Reports pack (11 Oct 2026): the sales report links (sales by brand
            // and channel, margin, delivery economics, courier ageing).
            'canViewSalesReports' => fn () => (bool) $request->user()?->can('sales.reports.view'),
            // The two that show cost (margin, delivery economics): not Staff.
            'canViewCostReports' => fn () => (bool) $request->user()?->can('sales.reports.costs'),
            'canManageChartOfAccounts' => fn () => (bool) $request->user()?->canAny(['accounting.chart.head.create', 'accounting.chart.head.update', 'accounting.chart.head.delete']),
            'canCreateLedgerHeads' => fn () => (bool) $request->user()?->can('accounting.chart.head.create'),
            'canUpdateLedgerHeads' => fn () => (bool) $request->user()?->can('accounting.chart.head.update'),
            'canDeleteLedgerHeads' => fn () => (bool) $request->user()?->can('accounting.chart.head.delete'),
            'canManageChartStructure' => fn () => (bool) $request->user()?->can('accounting.chart.structure.manage'),
            'canManageMoneyAccounts' => fn () => (bool) $request->user()?->can('accounting.money_account.manage'),
            // C-GL-6: the highest-risk permission in the system. Owner only.
            'canPrepareVouchers' => fn () => (bool) $request->user()?->can('accounting.voucher.prepare'),
            'canPostVouchers' => fn () => (bool) $request->user()?->can('accounting.voucher.post'),
            'canPostJournal' => fn () => (bool) $request->user()?->can('accounting.journal.post'),
            // Money LEAVING the business for a supplier. Deliberately not
            // `purchasing.payment.update`, which only guards a payment-status
            // string on a purchase order and moves nothing.
            'canRecordSupplierPayment' => fn () => (bool) $request->user()?->can('purchasing.payment.record'),
            // Purchases redesign (15 Oct 2026): the Tax rates (TDS/VDS) tab sits
            // behind purchasing.suppliers.manage, the gate on its route.
            'canManageSuppliers' => fn () => (bool) $request->user()?->can('purchasing.suppliers.manage'),
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
            'canViewStores' => fn () => (bool) $request->user()?->can('commerce.stores.view'),
            'canSell' => fn () => (bool) $request->user()?->can('sales.sell'),
            'canRecordOrders' => fn () => (bool) $request->user()?->can('sales.orders.record'),
            // Receive payment (21 Sep 2026): the menu offers it only to who may.
            'canRecordReceipts' => fn () => (bool) $request->user()?->can('sales.receipt.record'),
            'canManagePrices' => fn () => (bool) $request->user()?->can('sales.prices.manage'),
            'canViewCrm' => fn () => (bool) $request->user()?->can('crm.leads.view'),
            'canViewContacts' => fn () => (bool) $request->user()?->can('crm.contacts.view'),
            'canSendCampaigns' => fn () => (bool) $request->user()?->can('crm.campaigns.send'),
            'canViewCalendar' => fn () => (bool) $request->user()?->can('calendar.view'),
            'canViewNotes' => fn () => (bool) $request->user()?->can('notes.view'),
            'canViewFiles' => fn () => (bool) $request->user()?->can('files.view'),
            'canViewRecruitment' => fn () => (bool) $request->user()?->can('recruitment.view'),
            'canUseChat' => fn () => (bool) $request->user()?->can('chat.use'),
            'canManageCrmSettings' => fn () => (bool) $request->user()?->can('crm.settings.manage'),
            // Settings > Couriers: courier credentials and webhooks (11 Oct 2026).
            'canManageCourierSettings' => fn () => (bool) $request->user()?->can('couriers.settings.manage'),
            // Courier hub slice 5: rate cards and the courier fee report.
            'canProposeRateCards' => fn () => (bool) $request->user()?->can('couriers.ratecards.propose'),
            // Parcels to book (11 Oct 2026): the same pair the booking routes check.
            // The parcels list's own gate (bug hunt 16 Oct 2026): a "courier desk"
            // role with couriers.parcels.view alone could open the page by its
            // address but never saw it in the menu.
            'canSeeParcels' => fn () => (bool) $request->user()?->can('sales.parcels.see'),
            'canBookParcels' => fn () => (bool) $request->user()?->can('sales.sell') && (bool) $request->user()?->can('couriers.parcels.book'),
            // Settings > Dashboards (11 Oct 2026): the Owner's see-all permission.
            'canConfigureDashboards' => fn () => (bool) $request->user()?->can('dashboard.view_all'),
            // Settings > Failed jobs (11 Oct 2026): the queue's failures, Owner only.
            'canManageJobs' => fn () => (bool) $request->user()?->can('system.jobs.manage'),
            // The red count on the Settings home's Failed jobs card (15 Oct
            // 2026). ⚠ Null, not 0, for everybody else: a count that is
            // withheld is not a count of nothing, and the screen's existence
            // is not advertised to people who may not open it. One COUNT(*)
            // on failed_jobs, and only for the few who hold the permission.
            'failedJobsCount' => fn () => $request->user()?->can('system.jobs.manage') ? FailedJobs::count() : null,
            // Settings > Sounds and Settings > Popups (14 Oct 2026), Owner only.
            'canManagePopups' => fn () => (bool) $request->user()?->can('system.popups.manage'),
            // Settings > Relax zone (14 Oct 2026), Owner and Manager.
            'canManageRelax' => fn () => (bool) $request->user()?->can('relax.manage'),
            // Settings > Work schedule (14 Oct 2026), Owner and Manager.
            'canManageSchedule' => fn () => (bool) $request->user()?->can('schedule.manage'),
            // ⚠ The Assets module shipped loading MIGRATIONS ONLY - no routes, no
            // screens, no permissions - so nothing the business owns could be
            // recorded and depreciation had no caller at all. Reading the
            // register is its own permission rather than open to the business:
            // what the company owns and paid is closer to payroll than to stock.
            'canViewAssets' => fn () => (bool) $request->user()?->can('assets.view'),
            // The PHYSICAL register (16 Sep 2026): the Asset Keeper sees it
            // without the financial one.
            'canViewAssetItems' => fn () => (bool) $request->user()?->can('assets.items.view'),
            // The accounts hub (asset register slice 4, 13 Oct 2026).
            'canViewAccounts' => fn () => (bool) $request->user()?->can('assets.accounts.view'),
            // The leaver check (review of 13 Oct 2026, ruling 10) sits with the hub's managers.
            'canManageAccounts' => fn () => (bool) $request->user()?->can('assets.accounts.manage'),
            'canRunDepreciation' => fn () => (bool) $request->user()?->can('assets.depreciation.run'),
            // Self-scope (P3): whether the signed-in user has their own employee
            // record, so the "My profile" self-service link can show. Distinct
            // from canManageEmployees — an employee who isn't HR still has one.
            //
            // HRM-005 (audit 14 Oct 2026): and only in a business that employs
            // them. The record is central, but filing a claim, leave or a
            // staff purchase where nobody employs you sent the request to a
            // payroll that never includes you; the links follow the same rule
            // the self-service writes now enforce.
            'hasEmployeeProfile' => fn () => $request->user() !== null
                && (bool) Employee::where('user_id', $request->user()->id)->first()
                    ?->isEmployedIn(app(BusinessContext::class)->id()),
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

    /**
     * ⚠ A badge never breaks a page: if counting fails it shows nothing, and
     * the page itself says which source could not be read.
     */
    private function attentionCount(Request $request): int
    {
        $user = $request->user();
        $businessId = app(BusinessContext::class)->id();

        if (! $user || $businessId === null) {
            return 0;
        }

        try {
            return app(NeedsAttention::class)->count($user, (int) $businessId);
        } catch (\Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    /**
     * @return array{enabled: bool, business_channel: ?string, user_channel: ?string}
     */
    private function realtime(Request $request): array
    {
        $user = $request->user();
        $businessId = app(BusinessContext::class)->id();

        return [
            'enabled' => $user !== null && config('broadcasting.default') === 'reverb',
            'business_channel' => $businessId === null ? null : "business.{$businessId}",
            'user_channel' => $user === null ? null : "user.{$user->id}",
        ];
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
