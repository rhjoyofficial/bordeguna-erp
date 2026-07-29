import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface BusinessOption {
    id: number;
    name: string;
    slug: string;
}

interface Props {
    businesses: BusinessOption[];
    current_business_id: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Switch business',
        href: '/businesses/switch',
    },
];

export default function BusinessSwitcher({ businesses, current_business_id }: Props) {
    const [processing, setProcessing] = useState(false);

    /**
     * ⚠⚠ SWITCHING BUSINESS WAS COMPLETELY BROKEN UNTIL 2026-07-29. Read this
     * before "simplifying" it back to useForm().
     *
     * It was written as:
     *
     *     const { post } = useForm();                       // no data
     *     post(route('businesses.switch.store'), {
     *         data: { business_id: businessId },            // not a visit option
     *     } as never);
     *
     * `useForm().post(url, options)` sends the FORM'S OWN data — which was
     * empty — and `options` carries visit callbacks, not a payload. So it
     * POSTed `{}`, the controller's `business_id` validation rejected it, and
     * the user was silently returned to the same page having apparently
     * clicked nothing.
     *
     * ⚠ The `as never` cast is what hid it. TypeScript was correctly refusing
     * that object and the cast suppressed a compiler error that was telling the
     * truth — which is why `tsc` stayed clean while the feature did not work.
     * **A cast that silences a type error is a claim that you know better than
     * the compiler. Here, it wasn't true.**
     *
     * `router.post` takes the payload as its second argument, which is what was
     * wanted all along.
     */
    const selectBusiness = (businessId: number) => {
        setProcessing(true);

        router.post(
            route('businesses.switch.store'),
            { business_id: businessId },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Switch business" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-lg font-semibold">Switch business</h1>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {businesses.map((business) => (
                        <button
                            key={business.id}
                            type="button"
                            disabled={processing}
                            onClick={() => selectBusiness(business.id)}
                            className={`rounded-xl border p-4 text-left transition ${
                                business.id === current_business_id
                                    ? 'border-sidebar-primary bg-sidebar-primary/10'
                                    : 'border-sidebar-border/70 dark:border-sidebar-border hover:bg-sidebar-accent'
                            }`}
                        >
                            <div className="font-medium">{business.name}</div>
                            <div className="text-muted-foreground text-sm">{business.slug}</div>
                        </button>
                    ))}
                    {businesses.length === 0 && (
                        <p className="text-muted-foreground text-sm">You don't have access to any business yet.</p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
