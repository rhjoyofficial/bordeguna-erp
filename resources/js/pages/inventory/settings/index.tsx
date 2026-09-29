import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Inventory settings', href: '/inventory/settings' },
];

const money = (value: string | number) => `৳${Number(value).toLocaleString('en-BD', { maximumFractionDigits: 2 })}`;

/**
 * The approval limit for stock corrections.
 *
 * ⚠⚠ EMPTY IS A REAL ANSWER, AND IT IS THE DEFAULT. The column existed for
 * seven weeks with no screen, so it was empty everywhere, and empty means every
 * correction waits in Approvals - the Owner's own included. That is the safe
 * reading and this screen keeps it: it never suggests an amount, because who
 * may write off how much stock without a second look is the owner's number.
 */
export default function InventorySettings({ adjustment_approval_threshold }: { adjustment_approval_threshold: string | null }) {
    const { data, setData, put, errors, processing, isDirty } = useForm({
        adjustment_approval_threshold: adjustment_approval_threshold === null ? '' : String(Number(adjustment_approval_threshold)),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('inventory.settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory settings" />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <ReportHeading title="Inventory settings" />

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div>
                        <h2 className="font-medium">Approval limit for stock corrections</h2>
                    </div>

                    <FormRow className="gap-3">
                        <Field label="Limit (৳)" htmlFor="adjustment_approval_threshold" size="sm" error={errors.adjustment_approval_threshold}>
                            <Input
                                id="adjustment_approval_threshold"
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="any"
                                value={data.adjustment_approval_threshold}
                                onChange={(e) => setData('adjustment_approval_threshold', e.target.value)}
                                placeholder="empty"
                            />
                        </Field>
                        <Button disabled={processing || !isDirty} className="self-start sm:mt-6">
                            Save
                        </Button>
                    </FormRow>

                    <p className="text-sm">
                        Now:{' '}
                        <span className="font-medium">
                            {adjustment_approval_threshold === null
                                ? 'no amount is set, so every correction waits for approval.'
                                : `corrections worth up to ${money(adjustment_approval_threshold)} are saved at once.`}
                        </span>
                    </p>
                </form>
            </div>
        </AppLayout>
    );
}
