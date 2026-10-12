import { Button } from '@/components/ui/button';
import { Download, FileText, FileX, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, type ComponentType } from 'react';
import { PARSE_LIMIT, readableBytes } from './load';
import { PreviewLoading, PreviewMessage } from './states';
import { type PreviewKind, type ReaderProps } from './types';

export interface PreviewFile {
    name: string;
    filename: string;
    description: string | null;
    reference: string | null;
    previewable: PreviewKind | null;
    size: string;
    bytes: number;
    url: string;
}

/**
 * ⚠ EVERY READER IS ITS OWN CHUNK. SheetJS, docx-preview, JSZip and marked are
 * several hundred KB between them, and almost every visit to the archive opens
 * no spreadsheet at all. React.lazy fetches a reader the first time a file of
 * that kind is opened, so the archive page itself stays as small as it was.
 *
 * ⛔ A READER MUST NOT IMPORT A MODULE THAT ONLY THE FILES PAGE ALSO IMPORTS
 * (for example a lucide icon the page draws and no other screen does). Rollup
 * then serves it to the reader FROM THE PAGE'S CHUNK, the page chunk has to
 * export it, Vite's manifest drops the `resources/js/pages/files/index.tsx` key,
 * and every visit to /files fails with "Unable to locate file in Vite manifest".
 * The zip list draws no icons for exactly this reason. The files page tests
 * catch it, but only after `npm run build`.
 */
const readers: Partial<Record<PreviewKind, ComponentType<ReaderProps>>> = {
    text: lazy(() => import('./text-preview')),
    markdown: lazy(() => import('./markdown-preview')),
    sheet: lazy(() => import('./sheet-preview')),
    docx: lazy(() => import('./docx-preview')),
    pptx: lazy(() => import('./pptx-preview')),
    zip: lazy(() => import('./zip-preview')),
};

/** Kinds that are parsed whole in the browser, so their size matters. */
const PARSED: PreviewKind[] = ['sheet', 'docx', 'pptx', 'zip'];

const LABELS: Record<PreviewKind, string> = {
    image: 'Image',
    pdf: 'PDF',
    video: 'Video',
    audio: 'Audio',
    text: 'Text',
    markdown: 'Markdown',
    sheet: 'Spreadsheet',
    docx: 'Word document',
    pptx: 'PowerPoint',
    doc: 'Word document (old format)',
    ppt: 'PowerPoint (old format)',
    zip: 'Zip archive',
};

function Body({ file, downloadUrl }: { file: PreviewFile; downloadUrl: string }) {
    const kind = file.previewable;

    if (kind === 'image') return <img src={file.url} alt={file.name} className="mx-auto max-h-[70vh] w-auto max-w-full rounded-md border" />;
    if (kind === 'pdf') return <iframe src={file.url} title={file.name} className="h-[70vh] w-full rounded-md border" />;
    if (kind === 'video') return <video src={file.url} controls className="max-h-[70vh] w-full rounded-md border" />;
    if (kind === 'audio') return <audio src={file.url} controls className="w-full" />;

    if (kind === 'doc' || kind === 'ppt') {
        return (
            <PreviewMessage downloadUrl={downloadUrl} icon={<FileX className="size-8" />}>
                Preview is not available for old .{kind} files. Download it to open it in {kind === 'doc' ? 'Word' : 'PowerPoint'}.
            </PreviewMessage>
        );
    }

    const Reader = kind ? readers[kind] : undefined;
    if (!Reader) {
        return (
            <PreviewMessage downloadUrl={downloadUrl} icon={<FileText className="size-8" />}>
                This kind of file cannot be shown in the page. Download it to open it in whatever program reads it.
            </PreviewMessage>
        );
    }

    if (kind && PARSED.includes(kind) && file.bytes > PARSE_LIMIT) {
        return (
            <PreviewMessage downloadUrl={downloadUrl}>
                This file is too large to preview here ({readableBytes(file.bytes)}, the limit is {readableBytes(PARSE_LIMIT)}). Download it to open
                it.
            </PreviewMessage>
        );
    }

    return (
        <Suspense fallback={<PreviewLoading />}>
            <Reader url={file.url} downloadUrl={downloadUrl} filename={file.filename} />
        </Suspense>
    );
}

/**
 * The preview panel. Every kind is served from the SAME authenticated
 * attachments route the download uses: no second endpoint, no public URL.
 */
export function FilePreview({ file, onClose }: { file: PreviewFile; onClose: () => void }) {
    const panel = useRef<HTMLElement>(null);
    const downloadUrl = `${file.url}?download=1`;

    // Esc closes, unless a dialog or a field has the key.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || event.defaultPrevented) return;
            const target = event.target as HTMLElement | null;
            if (target?.closest('[role="dialog"], input, textarea, select')) return;
            onClose();
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    // Bring the panel into view when a file is opened from further down the list.
    useEffect(() => {
        panel.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [file.url]);

    return (
        <section ref={panel} aria-label={`Preview of ${file.name}`} className="min-w-0 space-y-3 rounded-lg border p-3 sm:p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="font-medium break-words">{file.name}</p>
                    <p className="text-muted-foreground text-xs break-all">
                        {file.filename} · {file.size} · {file.previewable ? LABELS[file.previewable] : 'File'}
                        {file.reference && <> · {file.reference}</>}
                    </p>
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={downloadUrl}>
                            <Download className="size-4" />
                            Download
                        </a>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={onClose} aria-label="Close preview" title="Close (Esc)">
                        <X className="size-4" />
                        Close
                    </Button>
                </div>
            </div>

            {file.description && <p className="text-muted-foreground max-w-2xl text-sm">{file.description}</p>}

            {/* Keyed by file so switching files never shows the last one's pages. */}
            <Body key={file.url} file={file} downloadUrl={downloadUrl} />
        </section>
    );
}
