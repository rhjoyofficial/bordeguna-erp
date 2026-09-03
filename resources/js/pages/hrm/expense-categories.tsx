import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

/**
 * The accountant's mapping screen: which account each employee-facing
 * expense category lands in. Staff never see this; remapping changes
 * future approvals only.
 */
interface CategoryRow {
    id: number;
    name: string;
    account_id: number;
    account: string;
    is_exception: boolean;
    is_active: boolean;
}

interface CategoriesProps {
    categories: CategoryRow[];
    accounts: { id: number; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expense categories', href: '/hrm/expense-categories' }];

export default function ExpenseCategoriesPage({ categories, accounts }: CategoriesProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense categories" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Expense categories and their accounts</h1>
                    <p className="text-muted-foreground text-sm">
                        Staff pick a category by name; this table decides which account it lands in at approval. Remapping changes future
                        approvals only - posted journals are history.
                    </p>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Category (what staff see)</th>
                                <th className="px-3 py-2 font-medium">Posts to (COA)</th>
                                <th className="px-3 py-2 font-medium">Exception</th>
                                <th className="px-3 py-2 font-medium">Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((category) => (
                                <tr key={category.id} className="border-t">
                                    <td className="px-3 py-2 font-medium">
                                        {category.name}
                                    </td>
                                    <td className="px-3 py-2">
                                        <select
                                            className="border-input bg-background rounded-md border px-2 py-1.5 text-sm"
                                            value={category.account_id}
                                            onChange={(e) =>
                                                router.patch(`/hrm/expense-categories/${category.id}`, { account_id: Number(e.target.value) }, { preserveScroll: true })
                                            }
                                        >
                                            {accounts.map((account) => (
                                                <option key={account.id} value={account.id}>
                                                    {account.label}
                                                </option>
                                            ))}
                                        </select>
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
                                                router.patch(`/hrm/expense-categories/${category.id}`, { is_active: e.target.checked }, { preserveScroll: true })
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground text-xs">
                    The exception badge marks catch-all categories (Other, Materials) - the approver should look twice before approving
                    those.
                </p>
            </div>
        </AppLayout>
    );
}
