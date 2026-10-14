import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { useTranslation } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

export interface LoginRow {
    id: number;
    label: string;
    kind: string;
    kind_label: string;
    privileged: boolean;
    holder_may_reveal: boolean;
    username: string | null;
    host_or_url: string | null;
    hint: string;
    note: string | null;
    needs_review: boolean;
    last_rotated_at: string | null;
    last_revealed_at: string | null;
    last_revealed_by: string | null;
    /** Whether THIS reader may reveal this row, decided by the server per login. */
    can_reveal: boolean;
    /**
     * FEA-005 (14 Oct 2026): the business this login belongs to, when this
     * reader may reveal it but only from there. Null otherwise.
     */
    reveal_in_business?: string | null;
}

export interface KindOption {
    value: string;
    label: string;
    privileged: boolean;
}

/** What the server hands the card; null means the card is absent for this reader. */
export interface LoginsCardData {
    rows: LoginRow[];
    can_manage: boolean;
    can_reveal: boolean;
    kinds: KindOption[];
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

function LoginForm({ storeUrl, login, kinds, onDone }: { storeUrl: string; login: LoginRow | null; kinds: KindOption[]; onDone: () => void }) {
    const { data, setData, post, put, errors, processing } = useForm({
        label: login?.label ?? '',
        kind: login?.kind ?? 'user_login',
        holder_may_reveal: login ? login.holder_may_reveal : true,
        username: login?.username ?? '',
        secret: '',
        host_or_url: login?.host_or_url ?? '',
        note: login?.note ?? '',
    });

    const privileged = kinds.find((k) => k.value === data.kind)?.privileged ?? false;

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
                <Label htmlFor="login-kind" required>
                    Kind
                </Label>
                <select
                    id="login-kind"
                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    value={data.kind}
                    onChange={(e) => setData('kind', e.target.value)}
                >
                    {kinds.map((kind) => (
                        <option key={kind.value} value={kind.value}>
                            {kind.label}
                        </option>
                    ))}
                </select>
                <InputError message={errors.kind} />
                {/* ⚠ An admin or recovery login is never the holder's: the server ignores the box for those. */}
                {privileged ? (
                    <p className="text-muted-foreground text-xs">Privileged: only Owner and IT Custodian can ever reveal it, never the holder.</p>
                ) : (
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.holder_may_reveal} onCheckedChange={(checked) => setData('holder_may_reveal', checked === true)} />
                        The current holder (or an employee with access) may reveal it
                    </label>
                )}
                <InputError message={errors.holder_may_reveal} />
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
                <PasswordInput
                    id="login-secret"
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
 * A new secret in place of the old one. The old value is gone the moment
 * this saves; what stays is when it was replaced and by whom.
 */
function ReplaceForm({ login, onDone }: { login: LoginRow; onDone: () => void }) {
    const { data, setData, post, errors, processing } = useForm({ secret: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/credentials/${login.id}/replace`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            <div className="grid gap-1">
                <Label htmlFor="replace-secret" required>
                    New password
                </Label>
                <PasswordInput
                    id="replace-secret"
                    value={data.secret}
                    onChange={(e) => setData('secret', e.target.value)}
                    autoComplete="new-password"
                    aria-required
                    autoFocus
                />
                <InputError message={errors.secret} />
            </div>
            <p className="text-muted-foreground text-xs">
                Change it at the device or the provider first, then record it here. The old one is not kept anywhere.
            </p>
            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button disabled={processing || data.secret === ''}>Replace</Button>
            </div>
        </form>
    );
}

/**
 * One masked row, and the reveal that shows its secret for thirty seconds.
 */
function LoginLine({ login, canManage, onEdit, onReplace }: { login: LoginRow; canManage: boolean; onEdit: () => void; onReplace: () => void }) {
    const [secret, setSecret] = useState<string | null>(null);
    const [left, setLeft] = useState(0);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);
    const { t } = useTranslation();

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
                cache: 'no-store',
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

            // FEA-005: the reveal route only finds a login of the business you stand in.
            if (response.status === 404) {
                setProblem(t('books.logins.other_business'));

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
                    <p className="flex flex-wrap items-center gap-1.5 font-medium">
                        {login.label}
                        <span className="text-muted-foreground inline-flex items-center gap-0.5 text-xs font-normal">
                            {login.privileged && <Lock className="size-3" aria-label="Privileged" />}
                            {login.kind_label}
                        </span>
                        {login.needs_review && (
                            <Badge variant="outline" className="border-amber-500 text-xs text-amber-700 dark:text-amber-400">
                                Needs review
                            </Badge>
                        )}
                    </p>
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
                    {login.can_reveal && secret === null && (
                        <Button size="sm" variant="outline" onClick={reveal} disabled={busy}>
                            {busy ? 'Checking' : 'Reveal'}
                        </Button>
                    )}
                    {!login.can_reveal && login.reveal_in_business && (
                        <span className="text-muted-foreground text-xs">
                            {t('books.logins.switch_business', { business: login.reveal_in_business })}
                        </span>
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
                            {login.needs_review && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => router.post(`/assets/credentials/${login.id}/reviewed`, {}, { preserveScroll: true })}
                                >
                                    Reviewed
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" onClick={onReplace}>
                                Replace
                            </Button>
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
            {(login.last_rotated_at || login.last_revealed_at) && (
                <p className="text-muted-foreground text-xs">
                    {login.last_rotated_at && <span>Replaced {login.last_rotated_at}. </span>}
                    {login.last_revealed_at && (
                        <span>
                            Last revealed {login.last_revealed_at}
                            {login.last_revealed_by && ` by ${login.last_revealed_by}`}
                        </span>
                    )}
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
 * something (holder, or a secrets permission) or manage. Everything shown is
 * masked until Reveal, which asks the server for the secret once; whether
 * THIS reader may reveal a given row is the row's own `can_reveal`, decided
 * by the server from the login's kind and holder policy.
 */
export function LoginsCard({ data, storeUrl, title = 'Logins' }: { data: LoginsCardData | null; storeUrl: string; title?: string }) {
    const [editing, setEditing] = useState<LoginRow | null | 'new'>(null);
    const [replacing, setReplacing] = useState<LoginRow | null>(null);

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
                            canManage={data.can_manage}
                            onEdit={() => setEditing(login)}
                            onReplace={() => setReplacing(login)}
                        />
                    ))}
                </ul>
            )}
            {data.can_reveal && (
                <p className="text-muted-foreground text-xs">
                    Reveal asks for your own password first. Once confirmed, reveals work for {data.confirm_minutes} minutes; each reveal is recorded,
                    the password itself never is. A login marked with a lock is privileged: only Owner and IT Custodian can reveal it.
                </p>
            )}

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Add a login' : `Edit ${editing?.label ?? ''}`}</DialogTitle>
                        <DialogDescription>Stored encrypted. Only people allowed to reveal it can ever read it back.</DialogDescription>
                    </DialogHeader>
                    {editing !== null && (
                        <LoginForm
                            storeUrl={storeUrl}
                            login={editing === 'new' ? null : editing}
                            kinds={data.kinds}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={replacing !== null} onOpenChange={(open) => !open && setReplacing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Replace the password of {replacing?.label ?? ''}</DialogTitle>
                        <DialogDescription>Records that the password was changed, and clears any review flag.</DialogDescription>
                    </DialogHeader>
                    {replacing !== null && <ReplaceForm login={replacing} onDone={() => setReplacing(null)} />}
                </DialogContent>
            </Dialog>
        </section>
    );
}
