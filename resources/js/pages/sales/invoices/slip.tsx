import { type Issuer } from '@/components/document-letterhead';
import {
    ThermalControls,
    ThermalDocument,
    type ThermalBarcode,
    type ThermalDocumentData,
    type ThermalProfileShape,
} from '@/components/thermal-document';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The counter slip (POS spec section 19).
 *
 * ⚠ This page decides nothing about the paper any more. Every figure was
 * formatted by the server, the layout comes from the printer profile, and the
 * barcode was either sized to whole printer dots or deliberately left off. All
 * this file does is put the document and the profile together and give the
 * cashier a button. Reprint is still simply visiting it again.
 */
export default function InvoiceSlip({
    invoiceId,
    invoiceNumber,
    document,
    profile,
    issuer,
    barcode,
}: {
    invoiceId: number;
    invoiceNumber: string;
    document: ThermalDocumentData;
    profile: ThermalProfileShape;
    issuer: Issuer;
    barcode: ThermalBarcode;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoiceNumber, href: `/sales/invoices/${invoiceId}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Slip ${invoiceNumber}`} />

            <div className="flex flex-col gap-4 p-4">
                <ThermalControls profile={profile}>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/invoices/${invoiceId}`}>Back to the invoice</Link>
                    </Button>
                </ThermalControls>

                {/* ⚠ Centred on screen, hard left on paper. A thermal roll feeds
                    from the left edge and has no page to centre within. */}
                <div className="mx-auto print:mx-0">
                    <ThermalDocument profile={profile} document={document} issuer={issuer} barcode={barcode} />
                </div>
            </div>
        </AppLayout>
    );
}
