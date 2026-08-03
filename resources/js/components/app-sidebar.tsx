import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Banknote, BookOpen, Boxes, CalendarCheck, CalendarClock, CalendarDays, CalendarRange, CheckSquare, Coins, FileBarChart, FileUp, Fingerprint, HandCoins, LayoutDashboard, Palmtree, PenSquare, ReceiptText, TrendingUp, Wallet, ClipboardList, LayoutGrid, PackageSearch, Repeat, Ruler, ShoppingCart, Tags, Truck, UserCircle, Users, Warehouse } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { pendingApprovalsCount, canReviewApprovals, canManageEmployees, canManageScheduling, canManageAttendance, canManageLeave, canAccessSalary, canManagePayroll, canViewAccounting, hasEmployeeProfile } =
        usePage<SharedData>().props;

    const navGroups: NavGroup[] = [
        {
            label: 'Overview',
            items: [
                { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                ...(canReviewApprovals
                    ? [{ title: 'Approvals', url: '/approvals', icon: CheckSquare, badge: pendingApprovalsCount }]
                    : []),
                ...(hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
                ...(hasEmployeeProfile ? [{ title: 'Clock in/out', url: '/hrm/clock', icon: Fingerprint }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My leave', url: '/hrm/my-leave', icon: Palmtree }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My payslips', url: '/hrm/my-payslips', icon: ReceiptText }] : []),
                { title: 'Switch business', url: '/businesses/switch', icon: Repeat },
            ],
        },
        {
            label: 'Inventory',
            items: [
                { title: 'Inventory', url: '/inventory', icon: Boxes },
                { title: 'Products', url: '/inventory/products', icon: PackageSearch },
                { title: 'Stock', url: '/inventory/stock', icon: ClipboardList },
                { title: 'Stock requests', url: '/inventory/stock-requests', icon: ClipboardList },
                { title: 'Warehouses', url: '/inventory/warehouses', icon: Warehouse },
                { title: 'Categories', url: '/inventory/categories', icon: Tags },
                { title: 'Units of measure', url: '/inventory/units', icon: Ruler },
            ],
        },
        {
            label: 'Purchasing',
            items: [
                { title: 'Suppliers', url: '/purchasing/suppliers', icon: Truck },
                { title: 'Purchase orders', url: '/purchasing/purchase-orders', icon: ShoppingCart },
            ],
        },
        ...(canManageEmployees
            ? [
                  {
                      label: 'People',
                      items: [{ title: 'Employees', url: '/hrm/employees', icon: Users }],
                  },
              ]
            : []),
        ...(canManageScheduling
            ? [
                  {
                      label: 'Scheduling',
                      items: [
                          { title: 'Schedules', url: '/hrm/schedules', icon: CalendarRange },
                          { title: 'Shifts', url: '/hrm/shifts', icon: CalendarClock },
                          { title: 'Calendars', url: '/hrm/calendars', icon: CalendarDays },
                      ],
                  },
              ]
            : []),
        ...(canManageAttendance
            ? [
                  {
                      label: 'Attendance',
                      items: [
                          { title: 'Daily attendance', url: '/hrm/attendance/daily', icon: CalendarCheck },
                          { title: 'Punch log', url: '/hrm/attendance', icon: Fingerprint },
                          { title: 'Corrections', url: '/hrm/attendance/corrections', icon: PenSquare },
                          { title: 'Import', url: '/hrm/attendance/import', icon: FileUp },
                      ],
                  },
              ]
            : []),
        ...(canManageLeave
            ? [
                  {
                      label: 'Leave',
                      items: [{ title: 'Leave & movement', url: '/hrm/leave', icon: Palmtree }],
                  },
              ]
            : []),
        ...(canAccessSalary
            ? [
                  {
                      label: 'Payroll',
                      items: [
                          { title: 'Salary structures', url: '/hrm/salary', icon: Wallet },
                          { title: 'Increments', url: '/hrm/salary-revisions', icon: TrendingUp },
                          ...(canManagePayroll
                              ? [
                                    { title: 'Payroll inputs', url: '/hrm/payroll-inputs', icon: Coins },
                                    { title: 'Loans & advances', url: '/hrm/loans', icon: HandCoins },
                                    { title: 'Payroll runs', url: '/hrm/payroll', icon: Banknote },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
        // Accounting Phase 2. Gated on accounting.view, which is Owner, Finance
        // and Payroll by default - a general ledger eventually carries a line
        // per salary payment, so reading it is close to reading payroll.
        ...(canViewAccounting
            ? [
                  {
                      label: 'Accounting',
                      items: [{ title: 'Chart of accounts', url: '/accounting/accounts', icon: BookOpen }],
                  },
              ]
            : []),
        // Reporting (W3). Shown to anyone who can reach at least one report;
        // the list itself is filtered per user server-side by ReportRegistry,
        // which also re-applies ACL-02 — so a Department Manager sees the
        // section, and no payroll report inside it.
        ...(canManageAttendance || canAccessSalary
            ? [
                  {
                      label: 'Reporting',
                      items: [
                          // RPT-04. Same gate as Reports and for the same
                          // reason: the widget list is filtered per user
                          // server-side by DashboardRegistry, which re-applies
                          // ACL-02 — so a Department Manager sees the section
                          // and no payroll tile inside it.
                          { title: 'HR dashboard', url: '/hrm/dashboard', icon: LayoutDashboard },
                          { title: 'Reports', url: '/hrm/reports', icon: FileBarChart },
                      ],
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavGroups groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
