import { renderAsync } from 'docx-preview';
import { useEffect, useRef, useState } from 'react';
import { fetchBytes, PreviewError } from './load';
import { PreviewLoading, PreviewMessage } from './states';
import { type ReaderProps } from './types';

/**
 * Strip anything in the rendered document that could reach outside it.
 *
 * ⚠⚠ docx-preview builds the page with DOM calls rather than HTML strings, so
 * the text in a Word file is never parsed as markup. What it does copy across
 * are LINKS and IMAGE SOURCES from the file's own relationships, and a Word file
 * can point those anywhere: a `javascript:` hyperlink, or an image fetched from
 * somebody's server every time a colleague opens the letter. Only http(s),
 * mailto and in-document anchors stay clickable; only images carried inside the
 * file (data: or blob:) stay visible.
 */
function containExternal(root: HTMLElement): void {
    root.querySelectorAll('a').forEach((link) => {
        const href = link.getAttribute('href') ?? '';
        if (!/^(https?:|mailto:|#)/i.test(href)) link.removeAttribute('href');
        if (!href.startsWith('#')) {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer nofollow');
        }
    });
    root.querySelectorAll('[src]').forEach((node) => {
        if (!/^(data|blob):/i.test(node.getAttribute('src') ?? '')) node.removeAttribute('src');
    });
    root.querySelectorAll('image').forEach((node) => {
        const href = node.getAttribute('href') ?? node.getAttribute('xlink:href') ?? '';
        if (!/^(data|blob):/i.test(href)) node.remove();
    });
    root.querySelectorAll('iframe, object, embed, script').forEach((node) => node.remove());
}

export default function DocxPreview({ url, downloadUrl }: ReaderProps) {
    const container = useRef<HTMLDivElement>(null);
    const [state, setState] = useState<{ status: 'loading' | 'ready' } | { status: 'error'; message: string }>({ status: 'loading' });

    useEffect(() => {
        const controller = new AbortController();
        const target = container.current;
        setState({ status: 'loading' });

        (async () => {
            const bytes = await fetchBytes(url, controller.signal);
            if (controller.signal.aborted || !target) return;
            target.replaceChildren();
            await renderAsync(bytes, target, undefined, {
                className: 'docx',
                inWrapper: true,
                breakPages: true,
                ignoreLastRenderedPageBreak: true,
                // Embedded images as data: URLs, so containExternal can tell them
                // from anything that would be fetched from outside.
                useBase64URL: true,
                // ⛔ altChunk is a raw HTML or other document dropped into a Word
                // file. Never drawn here.
                renderAltChunks: false,
                renderComments: false,
                renderChanges: false,
                experimental: false,
            });
            containExternal(target);
            if (!controller.signal.aborted) setState({ status: 'ready' });
        })().catch((error: unknown) => {
            if (controller.signal.aborted) return;
            setState({
                status: 'error',
                message:
                    error instanceof PreviewError
                        ? error.message
                        : 'This Word file could not be drawn here. It may be damaged, or not a .docx at all. Download it to open it in Word.',
            });
        });

        return () => {
            controller.abort();
            target?.replaceChildren();
        };
    }, [url]);

    return (
        <div className="space-y-2">
            {state.status === 'loading' && <PreviewLoading />}
            {state.status === 'error' && <PreviewMessage downloadUrl={downloadUrl}>{state.message}</PreviewMessage>}
            {/* The rendered pages are wider than a phone: the box scrolls sideways. */}
            <div
                ref={container}
                className={state.status === 'ready' ? 'max-h-[70vh] overflow-auto rounded-md border bg-neutral-100 text-black' : 'hidden'}
            />
        </div>
    );
}
