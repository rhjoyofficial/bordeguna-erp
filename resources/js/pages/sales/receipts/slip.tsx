import { type Issuer } from '@/components/document-letterhead';
import { ThermalControls, ThermalDocument, type ThermalDocumentData, type ThermalProfileShape } from '@/components/thermal-document';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The money receipt on a till roll.
 *
 * ⚠ No barcode: nothing in this ERP scans a receipt, and a barcode nobody reads
 * is decoration taking up a third of the paper on a financial document. The
 * invoice slip carries one only because the returns screen genuinely accepts
 * that exact string.
 */
export default function ReceiptSlip({
    receiptId,
    receiptNumber,
    document,
    profile,
    issuer,
}: {
    receiptId: number;
    receiptNumber: string;
    document: ThermalDocumentData;
    profile: ThermalProfileShape;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: `Money receipt ${receiptNumber}`, href: `/sales/receipts/${receiptId}/print` }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt slip ${receiptNumber}`} />

            <div className="flex flex-col gap-4 p-4">
                <ThermalControls profile={profile}>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/receipts/${receiptId}/print`}>Full page version</Link>
                    </Button>
                </ThermalControls>

                <div className="mx-auto print:mx-0">
                    <ThermalDocument profile={profile} document={document} issuer={issuer} barcode={null} />
                </div>
            </div>
        </AppLayout>
    );
}
