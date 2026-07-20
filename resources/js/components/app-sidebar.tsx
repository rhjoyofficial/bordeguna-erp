import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/react';
import { Boxes, ClipboardList, LayoutGrid, PackageSearch, Repeat, Ruler, ShoppingCart, Tags, Truck, Warehouse } from 'lucide-react';
import AppLogo from './app-logo';

const navGroups: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
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
];

export function AppSidebar() {
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
