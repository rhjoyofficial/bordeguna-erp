import { useState } from 'react';
import { read, utils, type WorkBook } from 'xlsx';
import { fetchBytes, fetchText, useLoaded } from './load';
import { PreviewLoading, PreviewMessage, TruncatedNote } from './states';
import { type ReaderProps } from './types';

const ROW_LIMIT = 1000;
const COLUMN_LIMIT = 60;

interface Sheet {
    name: string;
    rows: string[][];
    truncatedRows: boolean;
    truncatedColumns: boolean;
}

/**
 * Excel and CSV files, one tab per sheet, each an HTML table.
 *
 * ⚠ SheetJS is the CDN build (0.20.x), not the npm `xlsx@0.18`, which carries an
 * unfixed prototype-pollution advisory. Cells are written into the table by
 * React as TEXT, never through SheetJS's own HTML export, so a cell holding
 * markup is shown, not run.
 *
 * ⚠ `sheetRows` stops parsing after the first rows, which is what keeps a 50,000
 * row ledger from freezing the tab. It sets `!fullref` when it cut something.
 */
async function loadSheets(url: string, filename: string, signal: AbortSignal): Promise<Sheet[]> {
    const options = { sheetRows: ROW_LIMIT + 1, dense: true, cellHTML: false, cellFormula: false } as const;

    // ⚠ A CSV is read as TEXT so Bangla and other UTF-8 survives. Handed over
    // as raw bytes, SheetJS guesses an old single-byte code page and mangles it.
    let book: WorkBook;
    if (filename.toLowerCase().endsWith('.csv')) {
        const { text } = await fetchText(url, signal);
        book = read(text, { ...options, type: 'string' });
    } else {
        book = read(await fetchBytes(url, signal), { ...options, type: 'array' });
    }

    return book.SheetNames.map((name) => {
        const sheet = book.Sheets[name];
        const all = utils.sheet_to_json<unknown[]>(sheet, { header: 1, raw: false, defval: '', blankrows: true });
        const widest = all.reduce((max, row) => Math.max(max, row.length), 0);

        return {
            name,
            rows: all.slice(0, ROW_LIMIT).map((row) => row.slice(0, COLUMN_LIMIT).map((cell) => (cell == null ? '' : String(cell)))),
            truncatedRows: all.length > ROW_LIMIT || sheet['!fullref'] !== undefined,
            truncatedColumns: widest > COLUMN_LIMIT,
        };
    });
}

/** Spreadsheet column letters: A, B ... Z, AA, AB. */
function columnName(index: number): string {
    let name = '';
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
        name = String.fromCharCode(65 + ((n - 1) % 26)) + name;
    }

    return name;
}

export default function SheetPreview({ url, downloadUrl, filename }: ReaderProps) {
    const loaded = useLoaded(url, (signal) => loadSheets(url, filename, signal));
    const [active, setActive] = useState(0);

    if (loaded.status === 'loading') return <PreviewLoading />;
    if (loaded.status === 'error') return <PreviewMessage downloadUrl={downloadUrl}>{loaded.message}</PreviewMessage>;

    const sheets = loaded.value;
    const sheet = sheets[Math.min(active, sheets.length - 1)];

    if (!sheet) return <PreviewMessage downloadUrl={downloadUrl}>This workbook has no sheets to show.</PreviewMessage>;

    const width = sheet.rows.reduce((max, row) => Math.max(max, row.length), 0);

    return (
        <div className="space-y-2">
            {sheets.length > 1 && (
                <div className="flex gap-1 overflow-x-auto border-b" role="tablist" aria-label="Sheets">
                    {sheets.map((s, index) => (
                        <button
                            key={s.name}
                            type="button"
                            role="tab"
                            aria-selected={index === active}
                            onClick={() => setActive(index)}
                            className={`-mb-px shrink-0 border-b-2 px-3 py-1.5 text-sm whitespace-nowrap ${
                                index === active ? 'border-primary font-medium' : 'text-muted-foreground border-transparent'
                            }`}
                        >
                            {s.name}
                        </button>
                    ))}
                </div>
            )}

            {sheet.rows.length === 0 ? (
                <p className="text-muted-foreground py-6 text-sm">This sheet is empty.</p>
            ) : (
                <div className="max-h-[70vh] overflow-auto rounded-md border">
                    <table className="w-max min-w-full border-collapse text-xs">
                        <thead className="bg-muted sticky top-0">
                            <tr>
                                <th className="text-muted-foreground border px-2 py-1 font-normal" />
                                {Array.from({ length: width }, (_, i) => (
                                    <th key={i} className="text-muted-foreground border px-2 py-1 font-normal">
                                        {columnName(i)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sheet.rows.map((row, r) => (
                                <tr key={r}>
                                    <td className="bg-muted/50 text-muted-foreground border px-2 py-1 text-right">{r + 1}</td>
                                    {Array.from({ length: width }, (_, c) => (
                                        <td key={c} className="max-w-xs border px-2 py-1 align-top break-words whitespace-pre-wrap">
                                            {row[c] ?? ''}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(sheet.truncatedRows || sheet.truncatedColumns) && (
                <TruncatedNote>
                    Showing the first {sheet.truncatedRows ? `${ROW_LIMIT} rows` : ''}
                    {sheet.truncatedRows && sheet.truncatedColumns ? ' and ' : ''}
                    {sheet.truncatedColumns ? `${COLUMN_LIMIT} columns` : ''} only. Download the file to see all of it.
                </TruncatedNote>
            )}
        </div>
    );
}
