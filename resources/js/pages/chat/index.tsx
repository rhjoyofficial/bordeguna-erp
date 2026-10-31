import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface Channel {
    id: number;
    name: string;
    topic: string | null;
    is_private: boolean;
    messages_count: number;
    unread: number;
}

interface Message {
    id: number;
    body: string | null;
    removed: boolean;
    edited: boolean;
    author: string | null;
    author_id: number;
    author_avatar: string | null;
    at: string | null;
}

interface Current {
    id: number;
    name: string;
    topic: string | null;
    is_private: boolean;
    members: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chat', href: '/chat' }];

/**
 * ⚠⚠ There is no websocket server on this project, so new messages arrive by
 * polling. Five seconds is a deliberate compromise: fast enough that a
 * conversation does not feel broken, slow enough that a page left open all day
 * is not hammering the server.
 */
const POLL_MS = 5000;

export default function ChatIndex({
    channels,
    current,
    messages: initialMessages,
    people,
    me,
}: {
    channels: Channel[];
    current: Current | null;
    messages: Message[];
    people: { id: number; name: string }[];
    me: number;
}) {
    const [messages, setMessages] = useState<Message[]>(initialMessages);
    const [creating, setCreating] = useState(false);
    const bottom = useRef<HTMLDivElement>(null);

    const send = useForm({ body: '' });
    const channelForm = useForm<{ name: string; topic: string; is_private: boolean; members: number[] }>({
        name: '',
        topic: '',
        is_private: false,
        members: [],
    });

    // ⚠ Reset when the channel changes, or the new channel briefly shows the
    // previous one's conversation.
    useEffect(() => setMessages(initialMessages), [initialMessages, current?.id]);

    useEffect(() => {
        if (!current) {
            return;
        }

        const timer = setInterval(async () => {
            const after = messages.length > 0 ? messages[messages.length - 1].id : 0;

            try {
                // ⚠⚠ A HAND-WRITTEN FETCH, AND DELIBERATELY A GET. This project
                // has no CSRF meta tag, so a hand-written POST comes back 419
                // and the failure is swallowed exactly like a quiet network
                // error. Polling is a read, so a GET is honest as well as safe.
                const response = await fetch(`/chat/${current.id}/poll?after=${after}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();

                if (data.messages?.length) {
                    setMessages((existing) => [...existing, ...data.messages]);
                }
            } catch {
                // ⚠ A dropped poll is not worth telling anybody about. The next
                // one in five seconds will pick up whatever was missed, because
                // the request asks for everything after the last id we hold.
            }
        }, POLL_MS);

        return () => clearInterval(timer);
    }, [current, messages]);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!current || send.data.body.trim() === '') {
            return;
        }

        send.post(route('chat.messages.store', current.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => send.reset(),
        });
    };

    const submitChannel: FormEventHandler = (e) => {
        e.preventDefault();
        channelForm.post(route('chat.channels.store'), {
            preserveScroll: true,
            onSuccess: () => {
                channelForm.reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chat" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">Chat</h2>
                </div>

                <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
                    <aside className="flex flex-col gap-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">Channels</span>
                            <Button variant="ghost" size="sm" onClick={() => setCreating((c) => !c)}>
                                {creating ? 'Cancel' : 'New'}
                            </Button>
                        </div>

                        {creating && (
                            <form onSubmit={submitChannel} className="flex flex-col gap-2 rounded-lg border p-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="channel_name" required>
                                        Name
                                    </Label>
                                    <Input
                                        id="channel_name"
                                        value={channelForm.data.name}
                                        onChange={(e) => channelForm.setData('name', e.target.value)}
                                        placeholder="warehouse"
                                    />
                                    <InputError message={channelForm.errors.name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="channel_topic">What it is for</Label>
                                    <Input
                                        id="channel_topic"
                                        value={channelForm.data.topic}
                                        onChange={(e) => channelForm.setData('topic', e.target.value)}
                                    />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={channelForm.data.is_private}
                                        onChange={(e) => channelForm.setData('is_private', e.target.checked)}
                                    />
                                    Private
                                </label>
                                {channelForm.data.is_private && (
                                    <div className="flex flex-col gap-1">
                                        <span className="text-muted-foreground text-xs">Who can see it</span>
                                        {people
                                            .filter((p) => p.id !== me)
                                            .map((person) => (
                                                <label key={person.id} className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        checked={channelForm.data.members.includes(person.id)}
                                                        onChange={(e) =>
                                                            channelForm.setData(
                                                                'members',
                                                                e.target.checked
                                                                    ? [...channelForm.data.members, person.id]
                                                                    : channelForm.data.members.filter((id) => id !== person.id),
                                                            )
                                                        }
                                                    />
                                                    {person.name}
                                                </label>
                                            ))}
                                    </div>
                                )}
                                <Button type="submit" size="sm" disabled={channelForm.processing}>
                                    Create
                                </Button>
                            </form>
                        )}

                        <nav className="flex flex-col gap-1">
                            {channels.map((channel) => (
                                <Link
                                    key={channel.id}
                                    href={`/chat?channel=${channel.id}`}
                                    preserveScroll
                                    className={`flex items-center justify-between rounded-md px-3 py-2 text-sm ${
                                        current?.id === channel.id ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                                    }`}
                                >
                                    <span className="truncate">
                                        {channel.is_private ? '🔒' : '#'} {channel.name}
                                    </span>
                                    {channel.unread > 0 && current?.id !== channel.id && <Badge variant="secondary">{channel.unread}</Badge>}
                                </Link>
                            ))}
                            {channels.length === 0 && <p className="text-muted-foreground p-3 text-sm">No channels yet.</p>}
                        </nav>
                    </aside>

                    <section className="flex min-h-[28rem] flex-col rounded-lg border">
                        {current ? (
                            <>
                                <header className="border-b p-3">
                                    <div className="font-medium">
                                        {current.is_private ? '🔒' : '#'} {current.name}
                                    </div>
                                    {current.topic && <div className="text-muted-foreground text-xs">{current.topic}</div>}
                                    {current.is_private && (
                                        <div className="text-muted-foreground text-xs">{current.members.map((m) => m.name).join(', ')}</div>
                                    )}
                                </header>

                                <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-3">
                                    {messages.map((message) => (
                                        <div key={message.id} className={message.author_id === me ? 'self-end text-right' : ''}>
                                            <div
                                                className={`text-muted-foreground flex items-center gap-1.5 text-xs ${message.author_id === me ? 'justify-end' : ''}`}
                                            >
                                                {message.author_avatar ? (
                                                    <img src={message.author_avatar} alt="" className="h-5 w-5 rounded-full object-cover" />
                                                ) : null}
                                                {message.author} · {message.at}
                                            </div>
                                            <div
                                                className={`inline-block max-w-prose rounded-lg px-3 py-2 text-sm ${
                                                    message.removed
                                                        ? 'text-muted-foreground italic'
                                                        : message.author_id === me
                                                          ? 'bg-primary text-primary-foreground'
                                                          : 'bg-muted'
                                                }`}
                                            >
                                                {/* ⚠ The row stays and says so. A message that
                                                    vanished without trace would turn every later
                                                    disagreement into one person's word against
                                                    another's. */}
                                                {message.removed ? 'This message was removed' : message.body}
                                            </div>
                                            {!message.removed && message.author_id === me && (
                                                <div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.delete(route('chat.messages.destroy', message.id), {
                                                                preserveScroll: true,
                                                            })
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    {messages.length === 0 && <p className="text-muted-foreground text-sm">Nothing said here yet.</p>}
                                    <div ref={bottom} />
                                </div>

                                <form onSubmit={submit} className="flex gap-2 border-t p-3">
                                    <Input
                                        value={send.data.body}
                                        onChange={(e) => send.setData('body', e.target.value)}
                                        placeholder={`Message ${current.name}`}
                                    />
                                    <Button type="submit" disabled={send.processing || send.data.body.trim() === ''}>
                                        Send
                                    </Button>
                                </form>
                            </>
                        ) : (
                            <p className="text-muted-foreground p-6 text-center text-sm">No channels yet.</p>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
