import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { BookOpen, Boxes, ClipboardList, Folder, LayoutGrid, PackageSearch, Repeat, Tags, Truck, Warehouse } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Switch business',
        url: '/businesses/switch',
        icon: Repeat,
    },
    {
        title: 'Inventory',
        url: '/inventory',
        icon: Boxes,
    },
    {
        title: 'Products',
        url: '/inventory/products',
        icon: PackageSearch,
    },
    {
        title: 'Stock',
        url: '/inventory/stock',
        icon: ClipboardList,
    },
    {
        title: 'Stock requests',
        url: '/inventory/stock-requests',
        icon: ClipboardList,
    },
    {
        title: 'Warehouses',
        url: '/inventory/warehouses',
        icon: Warehouse,
    },
    {
        title: 'Categories',
        url: '/inventory/categories',
        icon: Tags,
    },
    {
        title: 'Units of measure',
        url: '/inventory/units',
        icon: Tags,
    },
    {
        title: 'Suppliers',
        url: '/inventory/suppliers',
        icon: Truck,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
