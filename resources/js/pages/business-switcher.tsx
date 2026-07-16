import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

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
    const { post, processing } = useForm();

    const selectBusiness = (businessId: number) => {
        post(route('businesses.switch.store'), {
            data: { business_id: businessId },
        } as never);
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
