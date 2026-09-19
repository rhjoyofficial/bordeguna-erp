import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * The accountant's mapping screen: which account each employee-facing
 * expense category lands in. Staff never see this; remapping changes
 * future approvals only.
 *
 * ⚠⚠ IT COULD ONLY REMAP, NEVER CREATE, and that is what left the Hostinger
 * bill with nowhere to go (Joy, 9 Sep 2026). The account existed; the category
 * did not; the only way to file the spend was the catch-all the accountant's own
 * document forbids as a default. Adding one is now a form on this page.
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
    const form = useForm<{ name: string; name_bn: string; account_id: string; receipt_required: boolean }>({
        name: '',
        name_bn: '',
        account_id: '',
        receipt_required: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hrm/expense-categories', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense categories" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Expense categories and their accounts</h1>
                    <p className="text-muted-foreground text-sm">
                        Staff pick a category by name; this table decides which account it lands in at approval. Remapping changes future approvals
                        only - posted journals are history.
                    </p>
                </div>

                <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                    <p className="text-sm font-medium">Add a category</p>
                    <FormRow>
                        <Field label="Name staff will see" htmlFor="name" size="md" error={form.errors.name} required>
                            <Input
                                id="name"
                                value={form.data.name}
                                placeholder="ERP and Hosting"
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="Bangla name"
                            htmlFor="name_bn"
                            size="md"
                            error={form.errors.name_bn}
                            hint="Optional. Staff on the Bangla interface see this instead."
                        >
                            <Input id="name_bn" value={form.data.name_bn} onChange={(e) => form.setData('name_bn', e.target.value)} />
                        </Field>

                        <Field label="Posts to" htmlFor="account_id" className="min-w-72 flex-1" error={form.errors.account_id}>
                            <SearchSelect
                                newLedger={{ types: ['expense'], reload: ['accounts'] }}
                                id="account_id"
                                value={form.data.account_id}
                                onChange={(e) => form.setData('account_id', e.target.value)}
                            >
                                <option value="">Pick the account…</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={String(account.id)}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>

                        <Field label="Receipt" htmlFor="receipt_required" size="md">
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input
                                    id="receipt_required"
                                    type="checkbox"
                                    checked={form.data.receipt_required}
                                    onChange={(e) => form.setData('receipt_required', e.target.checked)}
                                />
                                Required
                            </label>
                        </Field>
                    </FormRow>

                    <Button disabled={form.processing}>Add category</Button>
                </form>

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

                <p className="text-muted-foreground text-xs">
                    The exception badge marks catch-all categories (Other, Materials) - the approver should look twice before approving those.
                    Unticking "receipt required" is for spends that genuinely have no paper, like rickshaw fare; every other category refuses a claim
                    line without a file.
                </p>
            </div>
        </AppLayout>
    );
}
