import { fetchText, TEXT_LIMIT, useLoaded } from './load';
import { PreviewLoading, PreviewMessage, TruncatedNote } from './states';
import { type ReaderProps } from './types';

/**
 * Plain text, logs, JSON and XML, drawn as text.
 *
 * ⚠ React writes the content as a TEXT node, so an XML file full of markup, or a
 * log that happens to contain a script tag, is shown, never run.
 */
export default function TextPreview({ url, downloadUrl, filename }: ReaderProps) {
    const loaded = useLoaded(url, (signal) => fetchText(url, signal));

    if (loaded.status === 'loading') return <PreviewLoading />;
    if (loaded.status === 'error') return <PreviewMessage downloadUrl={downloadUrl}>{loaded.message}</PreviewMessage>;

    const { truncated } = loaded.value;
    let text = loaded.value.text;

    // JSON is easier to read laid out. A cut-off file cannot be parsed, and a
    // file that is not valid JSON is shown exactly as it is.
    if (!truncated && filename.toLowerCase().endsWith('.json')) {
        try {
            text = JSON.stringify(JSON.parse(text), null, 2);
        } catch {
            /* shown as written */
        }
    }

    return (
        <div className="space-y-2">
            <pre className="bg-muted/30 max-h-[70vh] overflow-auto rounded-md border p-3 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap">
                {text === '' ? '(empty file)' : text}
            </pre>
            {truncated && (
                <TruncatedNote>Showing the first {Math.round(TEXT_LIMIT / 1024 / 1024)} MB only. Download the file to see all of it.</TruncatedNote>
            )}
        </div>
    );
}
