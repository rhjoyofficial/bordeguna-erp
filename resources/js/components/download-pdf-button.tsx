import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';

/**
 * Download this document as a PDF.
 *
 * ⚠⚠ A PLAIN ANCHOR, NOT AN INERTIA LINK, and that is not a style choice. An
 * Inertia visit expects a page back and would choke on a file; the browser has
 * to be allowed to treat this as a download.
 *
 * ⚠ RENDERS NOTHING WHEN THE SERVER HAS NO BROWSER INSTALLED. A button that
 * always fails is worse than no button: the person clicks it, gets an error,
 * and learns not to trust the ones that do work. The Print button beside it
 * still saves a PDF through the browser's own dialog, so nothing is lost.
 */
export function DownloadPdfButton({ type, id }: { type: string; id: number }) {
    const available = usePage().props.pdfExport === true;

    if (!available) {
        return null;
    }

    return (
        <Button variant="outline" size="sm" asChild className="print:hidden">
            <a href={`/documents/${type}/${id}/pdf`}>
                <Download className="size-4" />
                Download PDF
            </a>
        </Button>
    );
}
