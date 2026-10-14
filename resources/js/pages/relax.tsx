import { Head, Link, usePage } from '@inertiajs/react';
import { Clapperboard, Coffee, Music, Play, PlayCircle, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';

interface RelaxItem {
    id: number;
    title: string;
    description: string | null;
    kind: 'audio' | 'video' | 'youtube';
    media_url: string | null;
    media_mime: string | null;
    youtube_id: string | null;
}

/** The tabs of one browser talk on this channel: a start in one pauses the others. */
const CHANNEL = 'erp-relax';

const KIND_ICON = { audio: Music, video: Clapperboard, youtube: PlayCircle } as const;

/**
 * The Relax zone (14 Oct 2026): a calm page anybody signed in can open for a
 * short break. Owners and Managers keep the list at Settings > Relax zone.
 *
 * ⚠ NOTHING AUTOPLAYS, and ONE thing plays at a time. Starting an audio or a
 * video pauses every other element on the page and, through a
 * BroadcastChannel, in every other tab. A YouTube player cannot be paused from
 * outside its frame, so "pausing" it means unmounting its iframe; it is only
 * mounted once somebody presses play on that card, so no request reaches
 * YouTube for a video nobody chose.
 */
export default function Relax({ items }: { items: RelaxItem[] }) {
    const { t } = useTranslation();
    const { canManageRelax } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('relax.title'), href: '/relax' }];

    const media = useRef(new Map<number, HTMLMediaElement>());
    const channel = useRef<BroadcastChannel | null>(null);
    const [youtubeOpen, setYoutubeOpen] = useState<number | null>(null);

    /** Pause everything on this page except `keep` (null pauses all). */
    const pauseAll = useCallback((keep: number | null) => {
        media.current.forEach((element, id) => {
            if (id !== keep && !element.paused) {
                element.pause();
            }
        });
        setYoutubeOpen((open) => (open === keep ? open : null));
    }, []);

    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return;
        }

        // A message never comes back to the channel object that sent it, so
        // anything arriving here was started in another tab.
        const bc = new BroadcastChannel(CHANNEL);
        bc.onmessage = (event: MessageEvent<{ type?: string }>) => {
            if (event.data?.type === 'playing') {
                pauseAll(null);
            }
        };
        channel.current = bc;

        return () => {
            bc.close();
            channel.current = null;
        };
    }, [pauseAll]);

    const started = (id: number) => {
        pauseAll(id);
        channel.current?.postMessage({ type: 'playing' });
    };

    const openYoutube = (id: number) => {
        started(id);
        setYoutubeOpen(id);
    };

    const register = (id: number) => (element: HTMLMediaElement | null) => {
        if (element) {
            media.current.set(id, element);
        } else {
            media.current.delete(id);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('relax.title')} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
                <section className="from-primary/5 via-muted/40 to-background flex flex-col gap-4 rounded-2xl border bg-gradient-to-br p-5 sm:flex-row sm:items-center sm:justify-between sm:p-7">
                    <div className="flex items-start gap-4">
                        <span className="bg-background text-primary flex size-12 shrink-0 items-center justify-center rounded-full border shadow-sm">
                            <Coffee className="size-6" />
                        </span>
                        <div className="space-y-1">
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{t('relax.heading')}</h1>
                            <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">{t('relax.intro')}</p>
                        </div>
                    </div>
                    {canManageRelax && (
                        <Link
                            href="/settings/relax"
                            className="text-muted-foreground hover:text-foreground inline-flex shrink-0 items-center gap-1.5 self-start text-sm sm:self-center"
                        >
                            <Settings2 className="size-4" /> {t('relax.manage_link')}
                        </Link>
                    )}
                </section>

                {items.length === 0 ? (
                    <p className="text-muted-foreground rounded-2xl border border-dashed p-8 text-center text-sm">{t('relax.empty')}</p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {items.map((item) => {
                            const Icon = KIND_ICON[item.kind];

                            return (
                                <li
                                    key={item.id}
                                    className="bg-card flex flex-col gap-3 rounded-2xl border p-4 shadow-sm transition-shadow hover:shadow-md"
                                >
                                    {item.kind === 'video' && item.media_url && (
                                        <video
                                            ref={register(item.id)}
                                            className="aspect-video w-full rounded-xl bg-black"
                                            controls
                                            playsInline
                                            preload="metadata"
                                            onPlay={() => started(item.id)}
                                        >
                                            <source src={item.media_url} type={item.media_mime ?? undefined} />
                                        </video>
                                    )}

                                    {item.kind === 'youtube' &&
                                        item.youtube_id &&
                                        (youtubeOpen === item.id ? (
                                            <iframe
                                                className="aspect-video w-full rounded-xl bg-black"
                                                src={`https://www.youtube-nocookie.com/embed/${item.youtube_id}`}
                                                title={item.title}
                                                allow="encrypted-media; picture-in-picture"
                                                referrerPolicy="strict-origin-when-cross-origin"
                                                allowFullScreen
                                            />
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => openYoutube(item.id)}
                                                aria-label={t('relax.play_named', { title: item.title })}
                                                className="group from-muted to-muted/40 text-muted-foreground hover:text-foreground focus-visible:ring-ring flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-xl bg-gradient-to-br transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <span className="bg-background/80 flex size-14 items-center justify-center rounded-full border shadow-sm transition-transform group-hover:scale-105">
                                                    <Play className="size-6 translate-x-0.5" />
                                                </span>
                                                <span className="text-xs">{t('relax.youtube_note')}</span>
                                            </button>
                                        ))}

                                    <div className="space-y-1.5">
                                        <div className="flex items-start justify-between gap-2">
                                            <h2 className="leading-snug font-medium">{item.title}</h2>
                                            <Badge variant="secondary" className="shrink-0 gap-1">
                                                <Icon className="size-3" /> {t(`relax.kind.${item.kind}`)}
                                            </Badge>
                                        </div>
                                        {item.description && (
                                            <p className="text-muted-foreground text-sm leading-relaxed whitespace-pre-line">{item.description}</p>
                                        )}
                                    </div>

                                    {item.kind === 'audio' && item.media_url && (
                                        <audio
                                            ref={register(item.id)}
                                            className="mt-auto w-full"
                                            controls
                                            preload="metadata"
                                            onPlay={() => started(item.id)}
                                        >
                                            <source src={item.media_url} type={item.media_mime ?? undefined} />
                                        </audio>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
