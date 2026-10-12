import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { useMemo } from 'react';
import { fetchText, TEXT_LIMIT, useLoaded } from './load';
import { PreviewLoading, PreviewMessage, TruncatedNote } from './states';
import { type ReaderProps } from './types';

/**
 * Turn markdown into HTML that is safe to put in this page.
 *
 * ⚠⚠ MARKDOWN ALLOWS RAW HTML, and `marked` passes it straight through. The
 * file was written by whoever uploaded it and is read by somebody else, on this
 * origin, signed in. Every byte goes through DOMPurify before it touches the
 * page, and on top of its defaults:
 * - links open in a new tab and carry no referrer, and only http(s), mailto and
 *   in-page anchors survive;
 * - images load only when embedded in the file itself (data:). A remote image is
 *   a request to somebody else's server every time a colleague opens the file,
 *   which tells that server who read it and when. Its alt text is kept.
 */
function toSafeHtml(source: string): string {
    const raw = marked.parse(source, { async: false, gfm: true, breaks: false });

    const fragment = DOMPurify.sanitize(raw, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select', 'iframe', 'object', 'embed'],
        FORBID_ATTR: ['style'],
        RETURN_DOM_FRAGMENT: true,
    });

    // Worked on the sanitised tree, after DOMPurify has finished walking it.
    fragment.querySelectorAll('a').forEach((link) => {
        const href = link.getAttribute('href') ?? '';
        if (!/^(https?:|mailto:|#)/i.test(href)) link.removeAttribute('href');
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer nofollow');
    });
    fragment.querySelectorAll('img').forEach((image) => {
        if (!/^data:image\/(png|jpe?g|gif|webp);/i.test(image.getAttribute('src') ?? '')) {
            image.replaceWith(document.createTextNode(image.getAttribute('alt') ?? ''));
        }
    });

    const holder = document.createElement('div');
    holder.appendChild(fragment);

    return holder.innerHTML;
}

// Typography for rendered markdown without a plugin: plain Tailwind variants on
// the container, so headings, lists, tables and code read like a document.
const prose = [
    'max-h-[70vh] overflow-auto rounded-md border p-4 text-sm leading-relaxed break-words',
    '[&_h1]:mt-4 [&_h1]:mb-2 [&_h1]:text-xl [&_h1]:font-semibold',
    '[&_h2]:mt-4 [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold',
    '[&_h3]:mt-3 [&_h3]:mb-1 [&_h3]:font-semibold [&_h4]:mt-3 [&_h4]:font-medium',
    '[&_p]:my-2 [&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:my-0.5',
    '[&_a]:text-primary [&_a]:underline [&_strong]:font-semibold',
    '[&_blockquote]:text-muted-foreground [&_blockquote]:my-2 [&_blockquote]:border-l-4 [&_blockquote]:pl-3',
    '[&_code]:bg-muted [&_code]:rounded [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs',
    '[&_pre]:bg-muted [&_pre]:my-2 [&_pre]:overflow-auto [&_pre]:rounded-md [&_pre]:p-3 [&_pre_code]:bg-transparent [&_pre_code]:p-0',
    '[&_table]:my-2 [&_table]:block [&_table]:overflow-auto [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1 [&_th]:text-left',
    '[&_hr]:my-4 [&_img]:max-w-full',
].join(' ');

export default function MarkdownPreview({ url, downloadUrl }: ReaderProps) {
    const loaded = useLoaded(url, (signal) => fetchText(url, signal));
    const html = useMemo(() => (loaded.status === 'ready' ? toSafeHtml(loaded.value.text) : ''), [loaded]);

    if (loaded.status === 'loading') return <PreviewLoading />;
    if (loaded.status === 'error') return <PreviewMessage downloadUrl={downloadUrl}>{loaded.message}</PreviewMessage>;

    return (
        <div className="space-y-2">
            {/* Sanitised above: see toSafeHtml. */}
            <div className={prose} dangerouslySetInnerHTML={{ __html: html }} />
            {loaded.value.truncated && (
                <TruncatedNote>Showing the first {Math.round(TEXT_LIMIT / 1024 / 1024)} MB only. Download the file to see all of it.</TruncatedNote>
            )}
        </div>
    );
}
