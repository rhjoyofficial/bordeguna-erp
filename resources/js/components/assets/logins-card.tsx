import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

export interface LoginRow {
    id: number;
    label: string;
    username: string | null;
    host_or_url: string | null;
    hint: string;
    note: string | null;
    last_revealed_at: string | null;
    last_revealed_by: string | null;
}

/** What the server hands the card; null means the card is absent for this reader. */
export interface LoginsCardData {
    rows: LoginRow[];
    can_manage: boolean;
    can_reveal: boolean;
    confirm_minutes: number;
}

/** How long a revealed secret stays on screen. */
const SHOW_FOR_SECONDS = 30;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Send the person to re-type their password, and bring them back here.
 *
 * ⚠ RequirePassword answers a JSON request with 423 and does not know where
 * to come back to, so the page tells the server itself through the small
 * confirm helper route, which stores this page as the intended URL.
 */
function goConfirmPassword() {
    const back = window.location.pathname + window.location.search;
    router.visit(`/assets/credentials/confirm?back=${encodeURIComponent(back)}`);
}

function LoginForm({ storeUrl, login, onDone }: { storeUrl: string; login: LoginRow | null; onDone: () => void }) {
    const { data, setData, post, put, errors, processing } = useForm({
        label: login?.label ?? '',
        username: login?.username ?? '',
        secret: '',
        host_or_url: login?.host_or_url ?? '',
        note: login?.note ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (login) {
            put(`/assets/credentials/${login.id}`, { preserveScroll: true, onSuccess: onDone });
        } else {
            post(storeUrl, { preserveScroll: true, onSuccess: onDone });
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            <div className="grid gap-1">
                <Label htmlFor="login-label" required>
                    What it opens
                </Label>
                <Input
                    id="login-label"
                    value={data.label}
                    onChange={(e) => setData('label', e.target.value)}
                    placeholder="Windows login, Router admin, cPanel"
                    aria-required
                    autoFocus
                />
                <InputError message={errors.label} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="login-username">Username</Label>
                <Input id="login-username" value={data.username} onChange={(e) => setData('username', e.target.value)} autoComplete="off" />
                <InputError message={errors.username} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="login-secret" required={!login}>
                    Password
                </Label>
                {/* ⚠ Never pre-filled. On an edit a blank keeps the stored one. */}
                <Input
                    id="login-secret"
                    type="password"
                    value={data.secret}
                    onChange={(e) => setData('secret', e.target.value)}
                    autoComplete="new-password"
                    placeholder={login ? 'Leave blank to keep the current one' : ''}
                    aria-required={!login}
                />
                <InputError message={errors.secret} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="login-host">Host or URL</Label>
                <Input
                    id="login-host"
                    value={data.host_or_url}
                    onChange={(e) => setData('host_or_url', e.target.value)}
                    placeholder="192.168.0.1 or https://..."
                />
                <InputError message={errors.host_or_url} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="login-note">Note</Label>
                <textarea
                    id="login-note"
                    className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                />
                <InputError message={errors.note} />
            </div>
            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button disabled={processing}>{login ? 'Save' : 'Add login'}</Button>
            </div>
        </form>
    );
}

/**
 * One masked row, and the reveal that shows its secret for thirty seconds.
 */
function LoginLine({ login, canReveal, canManage, onEdit }: { login: LoginRow; canReveal: boolean; canManage: boolean; onEdit: () => void }) {
    const [secret, setSecret] = useState<string | null>(null);
    const [left, setLeft] = useState(0);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);

    // ⚠ Hidden again after thirty seconds, and the copy of it in state is
    // dropped: a secret left on a screen is a secret on a screenshot.
    useEffect(() => {
        if (secret === null) {
            return;
        }

        setLeft(SHOW_FOR_SECONDS);
        timer.current = window.setInterval(() => {
            setLeft((current) => {
                if (current <= 1) {
                    setSecret(null);

                    return 0;
                }

                return current - 1;
            });
        }, 1000);

        return () => {
            if (timer.current !== null) {
                window.clearInterval(timer.current);
            }
        };
    }, [secret]);

    const reveal = async () => {
        setBusy(true);
        setProblem(null);

        try {
            const response = await fetch(`/assets/credentials/${login.id}/reveal`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: '{}',
            });

            // ⚠ 423 = "confirm your password first". The person goes to the
            // confirm page and comes straight back here.
            if (response.status === 423) {
                goConfirmPassword();

                return;
            }

            if (response.status === 429) {
                setProblem('Too many reveals in a minute. Wait a moment.');

                return;
            }

            if (response.status === 403) {
                setProblem('You may not see this login.');

                return;
            }

            if (!response.ok) {
                setProblem('Could not reveal it. Try again.');

                return;
            }

            const body = (await response.json()) as { secret: string };
            setSecret(body.secret);
            setCopied(false);
            // The page's "last revealed" stamps changed on the server.
            router.reload({ only: ['credentials'] });
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (secret === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
        } catch {
            setProblem('Copy is blocked in this browser. Select the text instead.');
        }
    };

    return (
        <li className="grid gap-1 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium">{login.label}</p>
                    <p className="text-muted-foreground text-xs break-all">
                        {login.username && <span>{login.username}</span>}
                        {login.username && login.host_or_url && ' at '}
                        {login.host_or_url && <span>{login.host_or_url}</span>}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {secret === null ? (
                        <span className="font-mono tracking-widest">••••{login.hint}</span>
                    ) : (
                        <span className="bg-muted rounded px-2 py-0.5 font-mono break-all select-all">{secret}</span>
                    )}
                    {canReveal && secret === null && (
                        <Button size="sm" variant="outline" onClick={reveal} disabled={busy}>
                            {busy ? 'Checking' : 'Reveal'}
                        </Button>
                    )}
                    {secret !== null && (
                        <>
                            <Button size="sm" variant="outline" onClick={copy}>
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setSecret(null)}>
                                Hide
                            </Button>
                            <span className="text-muted-foreground text-xs tabular-nums">{left}s</span>
                        </>
                    )}
                    {canManage && (
                        <>
                            <Button size="sm" variant="ghost" onClick={onEdit}>
                                Edit
                            </Button>
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="ghost" className="text-destructive">
                                        Delete
                                    </Button>
                                }
                                title={`Delete the login "${login.label}"?`}
                                description="The stored password is gone for good. Nothing can bring it back."
                                confirmLabel="Delete"
                                destructive
                                onConfirm={() => router.delete(`/assets/credentials/${login.id}`, { preserveScroll: true })}
                            />
                        </>
                    )}
                </div>
            </div>
            {login.note && <p className="text-muted-foreground text-xs whitespace-pre-line">{login.note}</p>}
            {login.last_revealed_at && (
                <p className="text-muted-foreground text-xs">
                    Last revealed {login.last_revealed_at}
                    {login.last_revealed_by && ` by ${login.last_revealed_by}`}
                </p>
            )}
            {problem && <p className="text-destructive text-xs">{problem}</p>}
        </li>
    );
}

/**
 * The logins a thing or an account carries (asset register slice 3).
 *
 * ⚠ Drawn only when the server handed a card at all: the reader may reveal
 * (holder, or a secrets permission) or manage. Everything shown is masked
 * until Reveal, which asks the server for the secret once.
 */
export function LoginsCard({ data, storeUrl, title = 'Logins' }: { data: LoginsCardData | null; storeUrl: string; title?: string }) {
    const [editing, setEditing] = useState<LoginRow | null | 'new'>(null);

    if (data === null) {
        return null;
    }

    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-medium">{title}</h2>
                {data.can_manage && (
                    <Button size="sm" variant="outline" onClick={() => setEditing('new')}>
                        Add login
                    </Button>
                )}
            </div>
            {data.rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">None stored.</p>
            ) : (
                <ul className="grid gap-2">
                    {data.rows.map((login) => (
                        <LoginLine
                            key={login.id}
                            login={login}
                            canReveal={data.can_reveal}
                            canManage={data.can_manage}
                            onEdit={() => setEditing(login)}
                        />
                    ))}
                </ul>
            )}
            {data.can_reveal && (
                <p className="text-muted-foreground text-xs">
                    Reveal asks for your own password first. Once confirmed, reveals work for {data.confirm_minutes} minutes; each reveal is recorded,
                    the password itself never is.
                </p>
            )}

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Add a login' : `Edit ${editing?.label ?? ''}`}</DialogTitle>
                        <DialogDescription>Stored encrypted. Only people allowed to reveal it can ever read it back.</DialogDescription>
                    </DialogHeader>
                    {editing !== null && <LoginForm storeUrl={storeUrl} login={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
                </DialogContent>
            </Dialog>
        </section>
    );
}
