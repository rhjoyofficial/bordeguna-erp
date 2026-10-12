import JSZip from 'jszip';
import { fetchBytes, useLoaded } from './load';
import { PreviewLoading, PreviewMessage, TruncatedNote } from './states';
import { type ReaderProps } from './types';

const SLIDE_LIMIT = 300;
const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

/**
 * A PowerPoint file's words, slide by slide.
 *
 * ⚠ There is no free in-browser renderer that draws slides faithfully, and a
 * half-drawn slide reads as a broken file. What IS reliable is the text: a .pptx
 * is a zip, each slide is ppt/slides/slideN.xml, and every run of text in it is
 * an <a:t> inside an <a:p> paragraph. Enough to know what a deck says and
 * whether it is the right one; the screen says plainly it is a text preview.
 */
async function loadSlides(url: string, signal: AbortSignal): Promise<{ slides: string[][]; total: number }> {
    const zip = await JSZip.loadAsync(await fetchBytes(url, signal));

    const files = Object.keys(zip.files)
        .map((path) => ({ path, match: /^ppt\/slides\/slide(\d+)\.xml$/.exec(path) }))
        .filter((f): f is { path: string; match: RegExpExecArray } => f.match !== null)
        .sort((a, b) => Number(a.match[1]) - Number(b.match[1]));

    if (files.length === 0) throw new Error('not a presentation');

    const parser = new DOMParser();
    const slides: string[][] = [];
    for (const file of files.slice(0, SLIDE_LIMIT)) {
        // Parsed as XML, never HTML, and only text content is read back out.
        const xml = parser.parseFromString(await zip.files[file.path].async('string'), 'application/xml');
        const paragraphs = Array.from(xml.getElementsByTagNameNS(DRAWING_NS, 'p'))
            .map((p) =>
                Array.from(p.getElementsByTagNameNS(DRAWING_NS, 't'))
                    .map((t) => t.textContent ?? '')
                    .join(''),
            )
            .filter((line) => line.trim() !== '');
        slides.push(paragraphs);
    }

    return { slides, total: files.length };
}

export default function PptxPreview({ url, downloadUrl }: ReaderProps) {
    const loaded = useLoaded(url, (signal) => loadSlides(url, signal));

    if (loaded.status === 'loading') return <PreviewLoading />;
    if (loaded.status === 'error') return <PreviewMessage downloadUrl={downloadUrl}>{loaded.message}</PreviewMessage>;

    const { slides, total } = loaded.value;

    return (
        <div className="space-y-2">
            <TruncatedNote>
                Text preview: the words on each slide, without the layout or pictures. Download the file for the full slides.
            </TruncatedNote>
            <ol className="max-h-[70vh] space-y-3 overflow-auto rounded-md border p-3">
                {slides.map((lines, index) => (
                    <li key={index} className="rounded-md border p-3">
                        <p className="text-muted-foreground mb-1 text-xs font-medium">Slide {index + 1}</p>
                        {lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm italic">No text on this slide.</p>
                        ) : (
                            lines.map((line, i) => (
                                <p key={i} className={i === 0 ? 'text-sm font-medium break-words' : 'text-sm break-words'}>
                                    {line}
                                </p>
                            ))
                        )}
                    </li>
                ))}
            </ol>
            {total > slides.length && (
                <TruncatedNote>
                    Showing the first {slides.length} of {total} slides.
                </TruncatedNote>
            )}
        </div>
    );
}
