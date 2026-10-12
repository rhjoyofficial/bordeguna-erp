import JSZip from 'jszip';
import { fetchBytes, readableBytes, useLoaded } from './load';
import { PreviewLoading, PreviewMessage, TruncatedNote } from './states';
import { type ReaderProps } from './types';

const ENTRY_LIMIT = 2000;

interface Entry {
    name: string;
    dir: boolean;
    size: number | null;
}

/**
 * What is inside a zip, by name and size. Nothing is extracted.
 *
 * ⚠ Reading the zip's directory decompresses nothing, so a zip bomb costs the
 * same as any other zip here. The uncompressed size comes from the directory
 * record JSZip keeps on each entry (`_data`); it is not part of JSZip's typed
 * API, so it is read defensively and simply left blank if a version drops it.
 */
async function loadEntries(url: string, signal: AbortSignal): Promise<{ entries: Entry[]; total: number }> {
    const zip = await JSZip.loadAsync(await fetchBytes(url, signal));
    const all = Object.values(zip.files);

    const entries = all
        .sort((a, b) => a.name.localeCompare(b.name))
        .slice(0, ENTRY_LIMIT)
        .map((entry) => {
            const size = (entry as unknown as { _data?: { uncompressedSize?: unknown } })._data?.uncompressedSize;

            return { name: entry.name, dir: entry.dir, size: typeof size === 'number' ? size : null };
        });

    return { entries, total: all.length };
}

export default function ZipPreview({ url, downloadUrl }: ReaderProps) {
    const loaded = useLoaded(url, (signal) => loadEntries(url, signal));

    if (loaded.status === 'loading') return <PreviewLoading />;
    if (loaded.status === 'error') return <PreviewMessage downloadUrl={downloadUrl}>{loaded.message}</PreviewMessage>;

    const { entries, total } = loaded.value;
    const fileCount = entries.filter((e) => !e.dir).length;

    if (entries.length === 0) return <PreviewMessage downloadUrl={downloadUrl}>This zip is empty.</PreviewMessage>;

    return (
        <div className="space-y-2">
            <TruncatedNote>
                {fileCount} {fileCount === 1 ? 'file' : 'files'} inside. Download the zip to open them.
            </TruncatedNote>
            <div className="max-h-[70vh] overflow-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted sticky top-0 text-left text-xs">
                        <tr>
                            <th className="px-3 py-1.5 font-medium">Name</th>
                            <th className="px-3 py-1.5 text-right font-medium">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((entry) => (
                            <tr key={entry.name} className="border-t">
                                <td className="px-3 py-1.5">
                                    {/* A folder's name already ends in "/"; it is only dimmed. */}
                                    <span className={entry.dir ? 'text-muted-foreground break-all' : 'break-all'}>{entry.name}</span>
                                </td>
                                <td className="text-muted-foreground px-3 py-1.5 text-right whitespace-nowrap">
                                    {entry.dir || entry.size === null ? '' : readableBytes(entry.size)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {total > entries.length && (
                <TruncatedNote>
                    Showing the first {entries.length} of {total} entries.
                </TruncatedNote>
            )}
        </div>
    );
}
