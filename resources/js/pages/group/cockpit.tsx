import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

/**
 * The group cockpit (Module 6, Slice 4): each company's vital signs, read
 * from its own books at view time, and honest plain-sum totals. When
 * intercompany pairs exist they will be eliminated here; until then the
 * screen says what the numbers are.
 */
interface CompanyRow {
    id: number;
    name: string;
    cash: number;
    receivables: number;
    payables: number;
    month_income: number;
    month_expense: number;
}

interface CockpitProps {
    companies: CompanyRow[];
    totals: { cash: number; receivables: number; payables: number; month_income: number; month_expense: number };
    month: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Group cockpit', href: '/group/cockpit' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function GroupCockpit({ companies, totals, month }: CockpitProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Group cockpit" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Group cockpit</h1>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Company</th>
                                <th className="px-3 py-2 text-right font-medium">Cash now</th>
                                <th className="px-3 py-2 text-right font-medium">Owed to us</th>
                                <th className="px-3 py-2 text-right font-medium">We owe</th>
                                <th className="px-3 py-2 text-right font-medium">Income ({month})</th>
                                <th className="px-3 py-2 text-right font-medium">Spend ({month})</th>
                            </tr>
                        </thead>
                        <tbody>
                            {companies.map((company) => (
                                <tr key={company.id} className="border-t">
                                    <td className="px-3 py-2 font-medium">{company.name}</td>
                                    <td className={'px-3 py-2 text-right tabular-nums ' + (company.cash < 0 ? 'text-destructive' : '')}>
                                        {money(company.cash)}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(company.receivables)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(company.payables)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums text-emerald-700">{money(company.month_income)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(company.month_expense)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-muted/30 border-t font-semibold">
                                <td className="px-3 py-2">The group</td>
                                <td className={'px-3 py-2 text-right tabular-nums ' + (totals.cash < 0 ? 'text-destructive' : '')}>
                                    {money(totals.cash)}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">{money(totals.receivables)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{money(totals.payables)}</td>
                                <td className="px-3 py-2 text-right tabular-nums text-emerald-700">{money(totals.month_income)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{money(totals.month_expense)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
