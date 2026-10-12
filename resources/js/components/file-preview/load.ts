import { useEffect, useState } from 'react';

/**
 * Shared loading for the in-page readers.
 *
 * ⚠⚠ EVERY READER FETCHES THE SAME AUTHENTICATED attachments.show URL the
 * download uses, with the session cookie and nothing else. There is no second
 * endpoint and no public link: whoever may not see the file gets the same 403 or
 * 404 here as on the download.
 */

/** Text kinds read at most this much; a 200 MB log should not freeze the tab. */
export const TEXT_LIMIT = 2 * 1024 * 1024;

/** Parsed kinds (sheets, Word, slides, zip) refuse files bigger than this. */
export const PARSE_LIMIT = 30 * 1024 * 1024;

export class PreviewError extends Error {}

async function fetchOk(url: string, signal: AbortSignal, init: RequestInit = {}): Promise<Response> {
    const response = await fetch(url, { credentials: 'same-origin', signal, ...init });
    if (!response.ok && response.status !== 206) {
        throw new PreviewError(response.status === 403 || response.status === 404 ? 'You cannot open this file.' : 'The file could not be loaded.');
    }

    return response;
}

/** The whole file as bytes. */
export async function fetchBytes(url: string, signal: AbortSignal): Promise<ArrayBuffer> {
    return (await fetchOk(url, signal)).arrayBuffer();
}

/**
 * The first TEXT_LIMIT bytes, decoded as UTF-8, and whether there was more.
 *
 * Asks for a byte range so a large file is not downloaded whole. The stream
 * honours Range on the local disk; on any disk that ignores it the full body
 * arrives and is cut here instead, so the answer is the same either way.
 */
export async function fetchText(url: string, signal: AbortSignal): Promise<{ text: string; truncated: boolean }> {
    const response = await fetchOk(url, signal, { headers: { Range: `bytes=0-${TEXT_LIMIT}` } });
    const bytes = new Uint8Array(await response.arrayBuffer());
    const truncated = bytes.byteLength > TEXT_LIMIT;
    // `fatal: false` turns a byte sequence cut mid-character into one
    // replacement mark at the end rather than an error.
    const text = new TextDecoder('utf-8', { fatal: false }).decode(truncated ? bytes.subarray(0, TEXT_LIMIT) : bytes);

    return { text, truncated };
}

export type Loaded<T> = { status: 'loading' } | { status: 'ready'; value: T } | { status: 'error'; message: string };

/** Run a loader once per url, cancelling it if the panel closes or switches file. */
export function useLoaded<T>(url: string, loader: (signal: AbortSignal) => Promise<T>): Loaded<T> {
    const [state, setState] = useState<Loaded<T>>({ status: 'loading' });

    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });
        loader(controller.signal).then(
            (value) => !controller.signal.aborted && setState({ status: 'ready', value }),
            (error: unknown) => {
                if (controller.signal.aborted) return;
                setState({
                    status: 'error',
                    message:
                        error instanceof PreviewError ? error.message : 'This file could not be read. It may be damaged, or not what its name says.',
                });
            },
        );

        return () => controller.abort();
        // The loader is recreated on every render by its caller; the url is what
        // decides whether there is anything new to load.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    return state;
}

export function readableBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
