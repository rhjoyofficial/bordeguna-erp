import { ThingDialog, type EditingThing, type ThingFormOptions } from '@/components/assets/thing-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * `/assets/items/create` and `/assets/items/{id}/edit` (slice A1). Since 13 Oct
 * 2026 the form is a popup opened from the list or the thing's page; these
 * addresses stay for the links and bookmarks that reach them, and open the
 * same popup at once. Closing it goes back where the popup would have.
 */
export default function AssetItemForm({ item, form }: { item: EditingThing | null; form: ThingFormOptions }) {
    const editing = item !== null;
    const backTo = editing ? `/assets/items/${item.id}` : '/assets/items';
    const [open, setOpen] = useState(true);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        editing ? { title: item.tag, href: backTo } : { title: 'Add a thing', href: '/assets/items/create' },
    ];

    const onOpenChange = (next: boolean) => {
        setOpen(next);

        if (!next) {
            router.visit(backTo);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? `Edit ${item.tag}` : 'Add a thing'} />
            <div className="flex flex-col gap-2 p-4">
                <h1 className="text-lg font-semibold">{editing ? `Edit ${item.tag}` : 'Add a thing'}</h1>
                <p className="text-muted-foreground text-sm">
                    The form opens in a popup.{' '}
                    <Link href={backTo} className="underline underline-offset-2">
                        {editing ? `Back to ${item.tag}` : 'Back to the list'}
                    </Link>
                </p>
            </div>
            <ThingDialog open={open} onOpenChange={onOpenChange} item={item} options={form} />
        </AppLayout>
    );
}
