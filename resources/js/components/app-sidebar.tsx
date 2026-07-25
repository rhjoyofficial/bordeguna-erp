import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Boxes, CalendarCheck, CalendarClock, CalendarDays, CalendarRange, CheckSquare, FileUp, Fingerprint, PenSquare, ClipboardList, LayoutGrid, PackageSearch, Repeat, Ruler, ShoppingCart, Tags, Truck, UserCircle, Users, Warehouse } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { pendingApprovalsCount, canReviewApprovals, canManageEmployees, canManageScheduling, canManageAttendance, hasEmployeeProfile } =
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
