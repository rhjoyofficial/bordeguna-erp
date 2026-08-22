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
    const { current_business_id } = usePage<SharedData>().props;
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

        try {
            const response = await fetch('/assistant/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                body: JSON.stringify({ messages: next.slice(-MAX_SENT) }),
            });

            const payload = (await response.json().catch(() => null)) as { reply?: string } | null;
            const reply = payload?.reply ?? 'The assistant is temporarily unavailable. Please try again in a minute.';
            setMessages((current) => [...current, { role: 'assistant', content: reply }]);
        } catch {
            setMessages((current) => [...current, { role: 'assistant', content: 'Could not reach the server. Check your connection and try again.' }]);
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
                className="border-border bg-primary text-primary-foreground fixed right-4 bottom-[4.5rem] z-40 flex h-11 w-11 items-center justify-center rounded-full border shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                            <button type="button" onClick={() => setOpen(false)} aria-label="Close" className="text-muted-foreground hover:text-foreground p-1">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto p-3">
                        {messages.length === 0 && (
                            <p className="text-muted-foreground px-1 pt-2 text-sm">
                                Ask me anything about using this ERP - where a screen is, how a workflow runs, what a report means. English or Bangla.
                            </p>
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
                            className="border-input bg-background h-9 min-w-0 flex-1 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
