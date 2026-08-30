import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Loader2, MessageCircle, Send, Trash2, X } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

type ChatMessage = { role: 'user' | 'assistant'; content: string };

const STORAGE_KEY = 'erp-assistant:transcript';
const MAX_SENT = 24;

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

export default function ErpAssistant() {
    const { current_business_id, assistantSuggestions } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>(loadTranscript);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const scrollRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-60)));
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [messages, open]);

    const send: FormEventHandler = async (e) => {
        e.preventDefault();
        const question = draft.trim();
        if (!question || busy) return;

        const next: ChatMessage[] = [...messages, { role: 'user', content: question }];
        setMessages(next);
        setDraft('');
        setBusy(true);

        // The reply is appended one piece at a time, so an empty assistant
        // bubble goes in first and is rewritten as text arrives.
        setMessages((current) => [...current, { role: 'assistant', content: '' }]);

        const replaceLast = (content: string) => setMessages((current) => [...current.slice(0, -1), { role: 'assistant', content }]);

        try {
            const response = await fetch('/assistant/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                body: JSON.stringify({ messages: next.slice(-MAX_SENT) }),
            });

            // A validation refusal still answers in JSON, before the stream
            // opens, so it is read the old way.
            if (!response.ok || !response.body) {
                const payload = (await response.json().catch(() => null)) as { reply?: string } | null;
                replaceLast(payload?.reply ?? 'The assistant is temporarily unavailable. Please try again in a minute.');
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
                    let data: { text?: string; message?: string } = {};

                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch {
                        continue;
                    }

                    if (event === 'text' && data.text) {
                        reply += data.text;
                        replaceLast(reply);
                    } else if (event === 'error') {
                        replaceLast(data.message ?? 'The assistant is temporarily unavailable. Please try again in a minute.');
                        return;
                    }
                }
            }

            if (reply === '') {
                replaceLast('I could not produce an answer. Please try rephrasing your question.');
            }
        } catch {
            replaceLast('Could not reach the server. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-label="Open the ERP guide chat"
                className="border-border bg-primary text-primary-foreground focus-visible:ring-ring fixed right-4 bottom-4 z-40 flex h-11 w-11 items-center justify-center rounded-full border shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:outline-none"
            >
                <MessageCircle className="h-5 w-5" />
            </button>

            {open && (
                <div className="border-border bg-card text-card-foreground fixed right-4 bottom-[7.75rem] z-40 flex h-[28rem] w-[22rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-xl border shadow-xl">
                    <div className="border-border flex items-center justify-between border-b px-4 py-2.5">
                        <div>
                            <p className="text-sm font-semibold">ERP guide</p>
                            <p className="text-muted-foreground text-xs">Ask how to do anything in this system</p>
                        </div>
                        <div className="flex items-center gap-1">
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
                                    Ask me about using this ERP, or about your own data - stock, invoices, your notes and to-do list. English or
                                    Bangla.
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
                        {messages.map((message, index) => (
                            <div key={index} className={cn('flex', message.role === 'user' ? 'justify-end' : 'justify-start')}>
                                <div
                                    className={cn(
                                        'max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                                        message.role === 'user' ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground',
                                    )}
                                >
                                    {message.content}
                                </div>
                            </div>
                        ))}
                        {busy && (
                            <div className="flex justify-start">
                                <div className="bg-muted text-muted-foreground flex items-center gap-2 rounded-lg px-3 py-2 text-sm">
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" /> Thinking
                                </div>
                            </div>
                        )}
                    </div>

                    <form onSubmit={send} className="border-border flex items-center gap-2 border-t p-2">
                        <input
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            placeholder="Type your question"
                            className="border-input bg-background focus-visible:ring-ring h-9 min-w-0 flex-1 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        <Button type="submit" size="icon" className="h-9 w-9 shrink-0" disabled={busy || draft.trim() === ''} aria-label="Send">
                            <Send className="h-4 w-4" />
                        </Button>
                    </form>
                </div>
            )}
        </>
    );
}
