import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { EmptyRow, Footnote, ReportHeading, ReportPage, ReportTable } from '@/components/report/report-shell';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Row {
    id: number;
    journal_id: number;
    journal_number: string;
    date: string;
    document: { label: string; number: string | null; url: string | null };
    po_number: string | null;
    po_url: string | null;
    challan: string | null;
    supplier_id: number | null;
    supplier: string | null;
    basis: string;
    received: string;
}

interface SupplierTotal {
    supplier_id: number | null;
    supplier: string | null;
    lines: number;
    open: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'GRNI not tagged to a supplier', href: '/accounting/grni-untagged' },
];

/**
 * ⭐ The exception list (A1, 18 Oct 2026): Goods Received Not Invoiced lines
 * posted before GRNI named its supplier. Read-only. The supplier shown comes
 * only from an exact document link; nothing is guessed and no posted line is
 * changed.
 */
export default function GrniUntaggedIndex({ rows, suppliers, total }: { rows: Row[]; suppliers: SupplierTotal[]; total: string }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="GRNI not tagged to a supplier" />
            <ReportPage>
                <ReportHeading
                    title="GRNI not tagged to a supplier"
                    description="Goods Received Not Invoiced lines that name no supplier in the ledger, with the supplier their own documents point to."
                />

                <Footnote>
                    Purchases now name the supplier on every GRNI line, so these are older lines (and orders that started before the change). Posted
                    lines are never rewritten. A positive figure is received and not billed yet; a negative one is billed, or returned, beyond what
                    was received on this path. A line no document links to a supplier stays under &quot;Supplier unknown&quot;: settle it from the
                    paperwork.
                </Footnote>

                <ReportTable
                    minWidth="36rem"
                    head={
                        <>
                            <th className="py-2">Supplier</th>
                            <th className="py-2 text-right">Lines</th>
                            <th className="py-2 text-right">Still open</th>
                        </>
                    }
                    foot={
                        <tr className="font-bold">
                            <td className="py-2" colSpan={2}>
                                Total
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={total} tone="total" />
                            </td>
                        </tr>
                    }
                >
                    {suppliers.map((row) => (
                        <tr key={row.supplier_id ?? 'unknown'} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">
                                {row.supplier_id === null ? (
                                    <span className="text-muted-foreground">Supplier unknown</span>
                                ) : (
                                    <DocNo href={route('accounting.supplier-ledger', { supplier: row.supplier_id })}>{row.supplier}</DocNo>
                                )}
                            </td>
                            <td className="py-2 text-right">{row.lines}</td>
                            <td className="py-2 text-right">
                                <Amount value={row.open} tone="balance" />
                            </td>
                        </tr>
                    ))}
                    {suppliers.length === 0 && <EmptyRow colSpan={3}>Every GRNI line names its supplier.</EmptyRow>}
                </ReportTable>

                {rows.length > 0 && (
                    <ReportTable
                        minWidth="64rem"
                        head={
                            <>
                                <th className="py-2">Date</th>
                                <th className="py-2">Document</th>
                                <th className="py-2">PO / challan</th>
                                <th className="py-2">Supplier</th>
                                <th className="py-2">How it is known</th>
                                <th className="py-2 text-right">Received, not billed</th>
                            </>
                        }
                    >
                        {rows.map((row) => (
                            <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2 whitespace-nowrap">
                                    <DateCell>{showDate(row.date)}</DateCell>
                                </td>
                                <td className="py-2">
                                    <div>
                                        {row.document.label}
                                        {row.document.number ? (
                                            <>
                                                {' '}
                                                <DocNo href={row.document.url}>{row.document.number}</DocNo>
                                            </>
                                        ) : null}
                                    </div>
                                    <DocNo href={route('accounting.journals.show', row.journal_id)} className="text-xs">
                                        {row.journal_number}
                                    </DocNo>
                                </td>
                                <td className="py-2 text-xs">
                                    {row.po_number && (
                                        <div>
                                            <DocNo href={row.po_url}>{row.po_number}</DocNo>
                                        </div>
                                    )}
                                    {row.challan && <div className="text-muted-foreground">Challan {row.challan}</div>}
                                </td>
                                <td className="py-2">{row.supplier ?? <span className="text-muted-foreground">Unknown</span>}</td>
                                <td className="text-muted-foreground py-2 text-xs">{row.basis}</td>
                                <td className="py-2 text-right">
                                    <Amount value={row.received} tone="balance" />
                                </td>
                            </tr>
                        ))}
                    </ReportTable>
                )}
            </ReportPage>
        </AppLayout>
    );
}
