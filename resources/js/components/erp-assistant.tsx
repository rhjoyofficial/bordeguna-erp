import HudhudIcon from '@/components/hudhud-icon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { dhakaClock } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Send, Trash2, X } from 'lucide-react';
import { FormEventHandler, KeyboardEventHandler, PointerEvent as ReactPointerEvent, useEffect, useRef, useState } from 'react';

type ChatMessage = { role: 'user' | 'assistant'; content: string; at?: number };

const LANGUAGE_KEY = 'erp-assistant-language';

const STORAGE_KEY = 'erp-assistant:transcript';
const SIZE_KEY = 'erp-assistant:size';
const MAX_SENT = 24;

/**
 * ⚠⚠ THE SIZE IT OPENS AT IS ALSO THE SMALLEST IT GOES (Joy, 30 Aug 2026).
 * Dragging can only ever make the panel bigger. A chat that can be shrunk to a
 * stamp is one somebody shrinks by accident and then reports as broken, and
 * there is no floor below this at which the header, the transcript and the
 * composer all still fit.
 */
const MIN_WIDTH = 352; // 22rem, the width it has always opened at
const MIN_HEIGHT = 448; // 28rem, likewise

/**
 * ⚠ The hand-written-fetch trap, respected: this is a POST, so it carries
 * the XSRF token read from the COOKIE Laravel always sets - never from a
 * meta tag the layout does not emit (that exact mistake silenced the
 * counter's price lookup for weeks). It also stamps X-Business-Id, the W3
 * stale-tab guard every mutating request in this app must carry.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function loadTranscript(): ChatMessage[] {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? (JSON.parse(raw) as ChatMessage[]) : [];
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function loadSize(): { width: number; height: number } {
    try {
        const raw = window.localStorage.getItem(SIZE_KEY);
        const parsed = raw ? (JSON.parse(raw) as { width?: number; height?: number }) : {};

        // ⚠ Clamped on the way in. A size stored on a wider screen, or one
        // edited by hand, must not be able to open the panel below the floor.
        return {
            width: Math.max(MIN_WIDTH, Number(parsed.width) || MIN_WIDTH),
            height: Math.max(MIN_HEIGHT, Number(parsed.height) || MIN_HEIGHT),
        };
    } catch {
        return { width: MIN_WIDTH, height: MIN_HEIGHT };
    }
}

/** HH:mm on Dhaka's clock (24 h), for the line under a bubble. */
function clockTime(at?: number): string {
    if (!at) return '';

    try {
        return dhakaClock(at).time; // Dhaka's clock, 24 h
    } catch {
        return '';
    }
}

export default function ErpAssistant() {
    const { auth, current_business_id, assistantSuggestions, assistantLanguages, assistantIdentity } = usePage<SharedData>().props;
    const getInitials = useInitials();

    // ⚠ Name, face and input ceiling are all set per business in Settings, AI
    // providers. The fallbacks matter only for the moment before the prop
    // exists - a page rendered by an older server should not crash the panel.
    const aiName = assistantIdentity?.name ?? 'Assistant';
    const aiAvatar = assistantIdentity?.avatar ?? null;
    const charLimit = assistantIdentity?.charLimit ?? 1000;

    // ⚠ Remembered per browser, because somebody who works in Bangla works in
    // Bangla every day and re-picking it each time is a small daily tax.
    const [language, setLanguage] = useState<string>(() => {
        try {
            const saved = window.localStorage.getItem(LANGUAGE_KEY);
            if (saved && assistantLanguages.some((l) => l.code === saved)) return saved;
        } catch {
            // A browser refusing storage is not a reason to fail to open.
        }
        return assistantLanguages[0]?.code ?? 'en';
    });
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>(loadTranscript);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    /** What it is doing WHILE you wait. Never part of the answer. */
    const [status, setStatus] = useState('');
    const [size, setSize] = useState(loadSize);
    const scrollRef = useRef<HTMLDivElement>(null);

    // ⚠ The drag handler closes over the size at pointer-down; this is what the
    // release reads, so what gets saved is the final size rather than the one
    // the drag started from.
    const sizeRef = useRef(size);

    useEffect(() => {
        sizeRef.current = size;
    }, [size]);

    useEffect(() => {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-60)));
        try {
            window.localStorage.setItem(LANGUAGE_KEY, language);
        } catch {
            // See above: storage may be refused.
        }
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [messages, open, busy, status, language]);

    /**
     * Drag the top-left corner to grow the panel.
     *
     * ⚠ The panel is pinned to the bottom-right, so moving the pointer LEFT and
     * UP is what makes it bigger - the deltas are subtracted, not added.
     */
    const startResize = (event: ReactPointerEvent<HTMLDivElement>) => {
        event.preventDefault();

        const startX = event.clientX;
        const startY = event.clientY;
        const startWidth = size.width;
        const startHeight = size.height;

        const move = (e: PointerEvent) => {
            setSize({
                width: Math.max(MIN_WIDTH, Math.min(startWidth - (e.clientX - startX), window.innerWidth - 32)),
                height: Math.max(MIN_HEIGHT, Math.min(startHeight - (e.clientY - startY), window.innerHeight - 96)),
            });
        };

        const stop = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', stop);

            try {
                window.localStorage.setItem(SIZE_KEY, JSON.stringify(sizeRef.current));
            } catch {
                // Storage may be refused; the size simply is not remembered.
            }
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', stop);
    };

    const overLimit = draft.length > charLimit;

    const send: FormEventHandler = async (e) => {
        e.preventDefault();
        const question = draft.trim();
        if (!question || busy || question.length > charLimit) return;

        const next: ChatMessage[] = [...messages, { role: 'user', content: question, at: Date.now() }];
        setMessages(next);
        setDraft('');
        setBusy(true);
        setStatus('');

        // ⚠ The assistant's bubble is not created until it actually says
        // something. An empty bubble sitting beside the typing dots reads as
        // two replies, one of them blank.
        let started = false;
        const push = (content: string) =>
            setMessages((current) => {
                if (!started) {
                    started = true;
                    return [...current, { role: 'assistant', content, at: Date.now() }];
                }

                const last = current[current.length - 1];

                return [...current.slice(0, -1), { role: 'assistant', content, at: last?.at ?? Date.now() }];
            });

        try {
            const response = await fetch('/assistant/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                // ⚠ role and content only. The timestamps belong to the panel,
                // and the endpoint validates a shape that has no room for them.
                body: JSON.stringify({
                    messages: next.slice(-MAX_SENT).map((m) => ({ role: m.role, content: m.content })),
                    language,
                }),
            });

            // A validation refusal still answers in JSON, before the stream
            // opens, so it is read the old way.
            if (!response.ok || !response.body) {
                const payload = (await response.json().catch(() => null)) as { reply?: string } | null;
                push(payload?.reply ?? 'The assistant is temporarily unavailable. Please try again in a minute.');
                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let reply = '';

            // Events are separated by a blank line. A chunk from the network
            // is not a whole event, so nothing is parsed until that separator
            // has actually been seen.
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let split = buffer.indexOf('\n\n');
                while (split !== -1) {
                    const block = buffer.slice(0, split);
                    buffer = buffer.slice(split + 2);
                    split = buffer.indexOf('\n\n');

                    const event = /^event:\s*(.+)$/m.exec(block)?.[1]?.trim();
                    const raw = /^data:\s*(.*)$/m.exec(block)?.[1] ?? '';
                    let data: { text?: string; message?: string; status?: string } = {};

                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch {
                        continue;
                    }

                    if (event === 'text' && data.text) {
                        reply += data.text;
                        // ⚠ The status said what it was doing before it spoke.
                        // Once there are words, it is doing this instead.
                        setStatus('');
                        push(reply);
                    } else if (event === 'status') {
                        setStatus(data.status ?? '');
                    } else if (event === 'error') {
                        setStatus('');
                        push(data.message ?? 'The assistant is temporarily unavailable. Please try again in a minute.');
                        return;
                    }
                }
            }

            if (reply === '') {
                push('I could not produce an answer. Please try rephrasing your question.');
            }
        } catch {
            push('Could not reach the server. Check your connection and try again.');
        } finally {
            setBusy(false);
            setStatus('');
        }
    };

    // Enter sends and Shift+Enter starts a line, the convention every chat app
    // on the phone in their pocket already follows.
    const onKeyDown: KeyboardEventHandler<HTMLTextAreaElement> = (event) => {
        if (event.key !== 'Enter' || event.shiftKey) return;

        event.preventDefault();

        if (!busy && draft.trim() !== '' && !overLimit) {
            send(event as unknown as Parameters<FormEventHandler>[0]);
        }
    };

    const assistantFace = (
        <Avatar className="h-7 w-7 shrink-0 overflow-hidden rounded-full">
            {aiAvatar ? <AvatarImage src={aiAvatar} alt={aiName} /> : null}
            <AvatarFallback className="bg-primary/10 text-primary">
                <HudhudIcon className="h-5 w-5" />
            </AvatarFallback>
        </Avatar>
    );

    return (
        <>
            <button
                type="button"
                data-app-chrome
                onClick={() => setOpen((value) => !value)}
                aria-label={'Open ' + aiName}
                className="border-border bg-card text-card-foreground focus-visible:ring-ring fixed right-4 bottom-4 z-40 flex h-11 w-11 items-center justify-center rounded-full border shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:outline-none"
            >
                <HudhudIcon className="h-7 w-7" />
            </button>

            {open && (
                <div
                    style={{ width: size.width, height: size.height }}
                    className="border-border bg-card text-card-foreground fixed right-4 bottom-[7.75rem] z-40 flex max-h-[calc(100vh-9rem)] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-xl border shadow-xl"
                >
                    {/* ⚠ The grip is top-left because the panel is pinned to the
                        bottom-right; that is the only corner free to move. */}
                    <div
                        onPointerDown={startResize}
                        role="separator"
                        aria-label="Resize the chat"
                        title="Drag to make the chat bigger"
                        className="hover:bg-accent absolute top-0 left-0 z-10 h-4 w-4 cursor-nwse-resize rounded-tl-xl"
                    >
                        <svg viewBox="0 0 10 10" className="text-muted-foreground h-2.5 w-2.5 translate-x-[3px] translate-y-[3px]">
                            <path d="M0 4 L4 0 M0 8 L8 0" stroke="currentColor" strokeWidth="1.2" fill="none" />
                        </svg>
                    </div>

                    <div className="border-border flex items-center justify-between gap-2 border-b py-2.5 pr-3 pl-4">
                        <div className="flex min-w-0 items-center gap-2">
                            {assistantFace}
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold">{aiName}</p>
                                <p className="text-muted-foreground truncate text-xs">
                                    {busy ? 'typing...' : 'Ask how to do anything in this system'}
                                </p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            {/* ⚠⚠ TWO LETTERS, BESIDE THE BIN (Joy, 30 Aug 2026).
                                The old control was a whole row under the header
                                carrying native language names, which spent a
                                line of the transcript on every screen to say
                                something that changes once a day at most. Which
                                pair appears is set per business in Settings. */}
                            {assistantLanguages.length === 2 && (
                                <div className="bg-muted flex items-center rounded-full p-0.5">
                                    {assistantLanguages.map((option) => (
                                        <button
                                            key={option.code}
                                            type="button"
                                            onClick={() => setLanguage(option.code)}
                                            aria-pressed={language === option.code}
                                            title={option.native + ' - replies come back in this language'}
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase transition-colors',
                                                language === option.code
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {option.code}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={() => setMessages([])}
                                aria-label="Clear conversation"
                                className="text-muted-foreground hover:text-foreground p-1"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                aria-label="Close"
                                className="text-muted-foreground hover:text-foreground p-1"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto p-3">
                        {messages.length === 0 && (
                            <div className="space-y-3 px-1 pt-2">
                                <p className="text-muted-foreground text-sm">
                                    Ask me about using this ERP, or about your own data - stock, invoices, your notes and to-do list.
                                </p>
                                {/* ⚠ Built server-side from what this person may
                                    actually see, so nobody is offered a question
                                    that would only come back refused. */}
                                {assistantSuggestions.length > 0 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {assistantSuggestions.map((suggestion) => (
                                            <button
                                                key={suggestion}
                                                type="button"
                                                onClick={() => setDraft(suggestion)}
                                                className="border-input hover:bg-accent rounded-full border px-2.5 py-1 text-left text-xs"
                                            >
                                                {suggestion}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}

                        {messages.map((message, index) => {
                            const mine = message.role === 'user';

                            return (
                                <div key={index} className={cn('flex items-end gap-2', mine ? 'justify-end' : 'justify-start')}>
                                    {/* ⚠ The face sits on the sender's side, the
                                        way every messaging app these people
                                        already use puts it. */}
                                    {!mine && assistantFace}
                                    <div className={cn('flex max-w-[80%] flex-col', mine ? 'items-end' : 'items-start')}>
                                        <div
                                            className={cn(
                                                'rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap',
                                                mine ? 'bg-primary text-primary-foreground rounded-br-sm' : 'bg-muted text-foreground rounded-bl-sm',
                                            )}
                                        >
                                            {message.content}
                                        </div>
                                        {message.at ? (
                                            <span className="text-muted-foreground mt-0.5 px-1 text-[10px]">{clockTime(message.at)}</span>
                                        ) : null}
                                    </div>
                                    {mine && (
                                        <Avatar className="h-7 w-7 shrink-0 overflow-hidden rounded-full">
                                            <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                                            <AvatarFallback className="bg-accent text-accent-foreground text-[10px]">
                                                {getInitials(auth.user.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                    )}
                                </div>
                            );
                        })}

                        {/* ⚠⚠ THE TYPING INDICATOR, AND IT IS NOT PART OF THE
                            ANSWER. "Looking that up..." used to be streamed as
                            reply text, so it was welded to the front of the
                            answer and stayed there for good. It now arrives on
                            its own event and lives here, where it disappears the
                            moment there are words to show. */}
                        {busy && (
                            <div className="flex items-end justify-start gap-2">
                                {assistantFace}
                                <div className="bg-muted flex items-center gap-2 rounded-2xl rounded-bl-sm px-3 py-2.5">
                                    <span className="flex items-center gap-1">
                                        <span className="bg-muted-foreground/60 h-1.5 w-1.5 animate-bounce rounded-full [animation-delay:-0.3s]" />
                                        <span className="bg-muted-foreground/60 h-1.5 w-1.5 animate-bounce rounded-full [animation-delay:-0.15s]" />
                                        <span className="bg-muted-foreground/60 h-1.5 w-1.5 animate-bounce rounded-full" />
                                    </span>
                                    {status ? <span className="text-muted-foreground text-xs">{status}</span> : null}
                                </div>
                            </div>
                        )}
                    </div>

                    <form onSubmit={send} className="border-border border-t p-2">
                        <div className="flex items-end gap-2">
                            <textarea
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={onKeyDown}
                                rows={1}
                                // ⚠ maxLength stops the typing; the endpoint
                                // checks the same number again, because an input
                                // attribute is a courtesy, not a control.
                                maxLength={charLimit}
                                placeholder="Type your question"
                                className="border-input bg-background focus-visible:ring-ring max-h-28 min-h-9 min-w-0 flex-1 resize-none rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            />
                            <Button
                                type="submit"
                                size="icon"
                                className="h-9 w-9 shrink-0"
                                disabled={busy || draft.trim() === '' || overLimit}
                                aria-label="Send"
                            >
                                <Send className="h-4 w-4" />
                            </Button>
                        </div>
                        {/* ⚠ Silent until it matters. A counter on screen from
                            the first keystroke reads as a warning about a limit
                            nobody was anywhere near. */}
                        {draft.length > charLimit * 0.8 && (
                            <p className={cn('mt-1 px-1 text-right text-[11px]', overLimit ? 'text-destructive' : 'text-muted-foreground')}>
                                {draft.length} / {charLimit}
                            </p>
                        )}
                    </form>
                </div>
            )}
        </>
    );
}
