import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Boxes, CalendarClock, CalendarDays, CheckSquare, ClipboardList, LayoutGrid, PackageSearch, Repeat, Ruler, ShoppingCart, Tags, Truck, UserCircle, Users, Warehouse } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { pendingApprovalsCount, canReviewApprovals, canManageEmployees, canManageScheduling, hasEmployeeProfile } = usePage<SharedData>().props;

    const navGroups: NavGroup[] = [
        {
            label: 'Overview',
            items: [
                { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                ...(canReviewApprovals
                    ? [{ title: 'Approvals', url: '/approvals', icon: CheckSquare, badge: pendingApprovalsCount }]
                    : []),
                ...(hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
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
                          { title: 'Shifts', url: '/hrm/shifts', icon: CalendarClock },
                          { title: 'Calendars', url: '/hrm/calendars', icon: CalendarDays },
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
