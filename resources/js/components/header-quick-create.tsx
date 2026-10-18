import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Contact,
    Factory,
    FileText,
    NotebookPen,
    PackageCheck,
    PackageSearch,
    Plus,
    ReceiptText,
    ShoppingCart,
    Store,
    Truck,
    Users,
    type LucideIcon,
} from 'lucide-react';

type QuickLink = { title: string; url: string; icon: LucideIcon };

/**
 * The header Create menu (Tally's Create, purchase redesign PR6, 18 Oct
 * 2026): first the daily ENTRIES (a purchase order, Buy and receive, a
 * payment, receipt or journal voucher, a production run, a sale), then the
 * RECORDS that most pages add in a popup on their list (products, customers,
 * suppliers, employees, an expense, an invoice). Opening stock is
 * deliberately absent: the one irreversible cutover document does not belong
 * one click from a convenience menu.
 */
export default function HeaderQuickCreate() {
    const { t } = useTranslation();
    const shared = usePage<SharedData>().props;
    const { canSell, canViewInventory, canViewPurchasing, canCreatePurchaseOrder, canViewSales, canManageEmployees } = shared;

    // Every shortcut wears the same permission its destination enforces
    // server-side (24 Aug 2026): an ungated shortcut here was how a
    // Self-service login still saw Product/Purchase order/Supplier on every
    // device after the sidebar was gated. PR6: the entries are gated by the
    // CREATE permission of their route, never by the read gate.
    const entries: QuickLink[] = [
        ...(canCreatePurchaseOrder ? [{ title: t('gw.c_po'), url: '/purchasing/purchase-orders/new', icon: ShoppingCart }] : []),
        ...(shared.canQuickPurchase ? [{ title: t('gw.c_buy'), url: '/purchasing/buy-and-receive/new', icon: PackageCheck }] : []),
        // accounting.voucher.prepare, the gate on vouchers/{type}/create.
        ...(shared.canPrepareVouchers
            ? [
                  { title: t('gw.c_payment'), url: '/accounting/vouchers/payment/create', icon: ArrowUpFromLine },
                  { title: t('gw.c_receipt'), url: '/accounting/vouchers/receipt/create', icon: ArrowDownToLine },
                  { title: t('gw.c_journal'), url: '/accounting/vouchers/journal/create', icon: NotebookPen },
              ]
            : []),
        // inventory.production.manage, the gate on production-runs/new.
        ...(canViewInventory && shared.inventoryAbilities?.manageProduction
            ? [{ title: t('gw.c_production'), url: '/inventory/production-runs/new', icon: Factory }]
            : []),
        ...(canSell ? [{ title: t('gw.c_sale'), url: '/sales/counter', icon: Store }] : []),
    ];

    const records: QuickLink[] = [
        ...(canViewInventory ? [{ title: 'Product', url: '/inventory/products', icon: PackageSearch }] : []),
        ...(canViewPurchasing ? [{ title: 'Expense', url: '/purchasing/expenses', icon: ReceiptText }] : []),
        ...(canViewSales ? [{ title: 'Invoice', url: '/sales/invoices/create', icon: FileText }] : []),
        ...(canViewSales ? [{ title: 'Customer', url: '/sales/customers', icon: Contact }] : []),
        ...(canViewPurchasing ? [{ title: 'Supplier', url: '/purchasing/suppliers', icon: Truck }] : []),
        ...(canManageEmployees ? [{ title: 'Employee', url: '/hrm/employees/create', icon: Users }] : []),
    ];

    // A Self-service login earns no shortcut at all: an empty menu is a
    // button that opens nothing.
    if (entries.length === 0 && records.length === 0) {
        return null;
    }

    const grid = (label: string, links: QuickLink[]) =>
        links.length === 0 ? null : (
            <div>
                <div className="text-muted-foreground px-2 pt-1 pb-1 text-xs font-medium">{label}</div>
                <div className="grid grid-cols-3 gap-1">
                    {links.map(({ title, url, icon: Icon }) => (
                        <Link
                            key={url}
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
            </div>
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm" className="gap-1.5">
                    <Plus className="h-4 w-4" />
                    <span className="hidden sm:inline">{t('gw.create')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="flex w-72 flex-col gap-2 p-2">
                {grid(t('gw.create_entries'), entries)}
                {grid(t('gw.create_records'), records)}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
