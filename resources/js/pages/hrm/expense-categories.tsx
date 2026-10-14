import { ExpenseCategoryDialog } from '@/components/hrm/expense-category-dialog';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The accountant's mapping screen: which account each employee-facing
 * expense category lands in. Staff never see this; remapping changes
 * future approvals only.
 *
 * ⚠⚠ IT COULD ONLY REMAP, NEVER CREATE, and that is what left the Hostinger
 * bill with nowhere to go (Joy, 9 Sep 2026). The account existed; the category
 * did not; the only way to file the spend was the catch-all the accountant's own
 * document forbids as a default. Adding one is now a popup on this page (a
 * form above the table until 14 Oct 2026).
 *
 * ⛔ Employees still do not create categories. A category is an accounting
 * decision - which head the money lands in - and this screen is behind the
 * payroll permission for exactly that reason.
 */
interface CategoryRow {
    id: number;
    name: string;
    name_bn: string | null;
    account_id: number;
    account: string;
    is_exception: boolean;
    receipt_required: boolean;
    is_active: boolean;
}

interface CategoriesProps {
    categories: CategoryRow[];
    accounts: { id: number; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expense categories', href: '/hrm/expense-categories' }];

export default function ExpenseCategoriesPage({ categories, accounts }: CategoriesProps) {
    // ⭐ Add opens a popup over the table (14 Oct 2026, Joy: "add edit will from
    // the pop up, not like current above the list"). There is no edit form: the
    // row's own switches save at once and stay exactly as they were.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense categories" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Expense categories and their accounts</h1>
                    <Button onClick={() => setAdding(true)}>Add category</Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Category (what staff see)</th>
                                <th className="px-3 py-2 font-medium">Posts to (COA)</th>
                                <th className="px-3 py-2 font-medium">Receipt required</th>
                                <th className="px-3 py-2 font-medium">Exception</th>
                                <th className="px-3 py-2 font-medium">Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((category) => (
                                <tr key={category.id} className="border-t">
                                    <td className="px-3 py-2 font-medium">
                                        {category.name}
                                        {category.name_bn && (
                                            <span className="text-muted-foreground block text-xs font-normal">{category.name_bn}</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="w-72">
                                            <SearchSelect
                                                newLedger={{ types: ['expense'], reload: ['accounts'] }}
                                                value={String(category.account_id)}
                                                onChange={(e) =>
                                                    router.patch(
                                                        `/hrm/expense-categories/${category.id}`,
                                                        { account_id: Number(e.target.value) },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {accounts.map((account) => (
                                                    <option key={account.id} value={String(account.id)}>
                                                        {account.label}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            checked={category.receipt_required}
                                            onChange={(e) =>
                                                router.patch(
                                                    `/hrm/expense-categories/${category.id}`,
                                                    { receipt_required: e.target.checked },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        {category.is_exception && (
                                            <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                needs extra scrutiny
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <input
                                            type="checkbox"
                                            checked={category.is_active}
                                            onChange={(e) =>
                                                router.patch(
                                                    `/hrm/expense-categories/${category.id}`,
                                                    { is_active: e.target.checked },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <ExpenseCategoryDialog open={adding} onOpenChange={setAdding} accounts={accounts} />
            </div>
        </AppLayout>
    );
}
