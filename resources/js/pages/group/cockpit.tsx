import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

/**
 * The group cockpit (Module 6, Slice 4): each company's vital signs, read
 * from its own books at view time. Money between our own businesses is kept
 * out of the trade columns and shown on its own; across the group it cancels.
 */
interface CompanyRow {
    id: number;
    name: string;
    cash: number;
    receivables: number;
    payables: number;
    month_income: number;
    month_expense: number;
    intercompany: number;
}

interface CockpitProps {
    companies: CompanyRow[];
    totals: { cash: number; receivables: number; payables: number; month_income: number; month_expense: number; intercompany: number };
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
                                <th
                                    className="px-3 py-2 text-right font-medium"
                                    title="Positive: our other businesses owe this one. Negative: this one owes them."
                                >
                                    Between our businesses
                                </th>
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
                                    <td className="px-3 py-2 text-right text-emerald-700 tabular-nums">{money(company.month_income)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(company.month_expense)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(company.intercompany)}</td>
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
                                <td className="px-3 py-2 text-right text-emerald-700 tabular-nums">{money(totals.month_income)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{money(totals.month_expense)}</td>
                                <td className={'px-3 py-2 text-right tabular-nums ' + (totals.intercompany !== 0 ? 'text-destructive' : '')}>
                                    {totals.intercompany === 0 ? 'Cancels out' : money(totals.intercompany)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                {totals.intercompany !== 0 && (
                    <p className="text-destructive text-sm">
                        Money between our businesses should cancel out across the group. It does not, so one side of a transfer is missing in a
                        business you cannot see here, or was entered by hand.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
