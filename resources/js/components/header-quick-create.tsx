import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Contact, FileText, PackageSearch, Plus, ReceiptText, ShoppingCart, Store, Truck, Users, type LucideIcon } from 'lucide-react';

type QuickLink = { title: string; url: string; icon: LucideIcon };

/**
 * The "Add New" grid from the reference header. Most master-data creation
 * here happens in a panel on the index page (suppliers, customers,
 * products, POs, expenses), so those shortcuts land on the index - the
 * form is one more click, and there is no /create route to deep-link.
 * Opening stock is deliberately absent: the one irreversible cutover
 * document does not belong one click from a convenience menu.
 */
export default function HeaderQuickCreate() {
    const { canSell, canViewInventory, canViewPurchasing, canCreatePurchaseOrder, canViewSales, canManageEmployees } = usePage<SharedData>().props;

    // Every shortcut wears the same permission its destination now enforces
    // server-side (24 Aug 2026) - an ungated shortcut here was how a
    // Self-service login still saw Product/Purchase order/Supplier on every
    // device after the sidebar was gated.
    const links: QuickLink[] = [
        ...(canSell ? [{ title: 'Counter sale', url: '/sales/counter', icon: Store }] : []),
        ...(canViewInventory ? [{ title: 'Product', url: '/inventory/products', icon: PackageSearch }] : []),
        // ⭐ PR1 (18 Oct 2026): the full New purchase order page, behind the
        // permission it needs (po.create), not the read gate.
        ...(canCreatePurchaseOrder ? [{ title: 'Purchase order', url: '/purchasing/purchase-orders/new', icon: ShoppingCart }] : []),
        ...(canViewPurchasing ? [{ title: 'Expense', url: '/purchasing/expenses', icon: ReceiptText }] : []),
        ...(canViewSales ? [{ title: 'Invoice', url: '/sales/invoices/create', icon: FileText }] : []),
        ...(canViewSales ? [{ title: 'Customer', url: '/sales/customers', icon: Contact }] : []),
        ...(canViewPurchasing ? [{ title: 'Supplier', url: '/purchasing/suppliers', icon: Truck }] : []),
        ...(canManageEmployees ? [{ title: 'Employee', url: '/hrm/employees/create', icon: Users }] : []),
    ];

    // A Self-service login earns no shortcut at all - an empty "Add New" menu
    // is a button that opens nothing.
    if (links.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm" className="gap-1.5">
                    <Plus className="h-4 w-4" />
                    <span className="hidden sm:inline">Add New</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72 p-2">
                <div className="grid grid-cols-3 gap-1">
                    {links.map(({ title, url, icon: Icon }) => (
                        <Link
                            key={title}
                            href={url}
                            className="hover:bg-accent hover:text-accent-foreground flex flex-col items-center gap-1.5 rounded-md p-3 text-center transition-colors"
                        >
                            <span className="bg-primary/10 text-primary flex h-9 w-9 items-center justify-center rounded-lg">
                                <Icon className="h-4.5 w-4.5" />
                            </span>
                            <span className="text-xs leading-tight">{title}</span>
                        </Link>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
