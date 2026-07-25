import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Punch {
    id: number;
    time: string;
    direction: string | null;
    source: string;
    needs_review: boolean;
    review_reason: string | null;
}

interface ClockProps {
    employee: { name: string; employee_code: string; office: string | null };
    today: string;
    punches: Punch[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clock in/out', href: '/hrm/clock' }];

export default function Clock({ employee, today, punches }: ClockProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [locationError, setLocationError] = useState<string | null>(null);

    /**
     * Location is captured in the browser and sent with the punch — the server
     * requires it, so a denied permission is explained here rather than turning
     * into a validation error the employee cannot act on.
     */
    const punch = (direction: 'in' | 'out') => {
        setLocationError(null);

        if (!navigator.geolocation) {
            setLocationError('This browser cannot share location. Ask HR to record your attendance manually.');
            return;
        }

        setBusy(direction);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                router.post(
                    route('hrm.clock.store'),
                    {
                        direction,
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        gps_accuracy_meters: position.coords.accuracy,
                        device_metadata: {
                            platform: navigator.platform,
                            language: navigator.language,
                            screen: `${window.screen.width}x${window.screen.height}`,
                        },
                    },
                    {
                        preserveScroll: true,
                        onFinish: () => setBusy(null),
                    },
                );
            },
            (error) => {
                setBusy(null);
                setLocationError(
                    error.code === error.PERMISSION_DENIED
                        ? 'Location permission was denied. Allow it in your browser, or ask HR to record your attendance manually.'
                        : 'Could not read your location. Try again, or ask HR to record your attendance manually.',
                );
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clock in/out" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">{employee.name}</h2>
                    <p className="text-sm text-muted-foreground">
                        {employee.employee_code}
                        {employee.office && ` · ${employee.office}`} · {today}
                    </p>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap gap-3 rounded-xl border p-4">
                    <Button onClick={() => punch('in')} disabled={busy !== null}>
                        {busy === 'in' ? 'Recording…' : 'Clock in'}
                    </Button>
                    <Button variant="outline" onClick={() => punch('out')} disabled={busy !== null}>
                        {busy === 'out' ? 'Recording…' : 'Clock out'}
                    </Button>
                </div>

                {locationError && <p className="text-sm text-destructive">{locationError}</p>}

                <p className="text-xs text-muted-foreground">
                    Your location, accuracy, IP address and device details are recorded with each punch. If you are away from your office the punch is
                    still accepted, and flagged for your manager to review.
                </p>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Today</h3>
                    {punches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nothing recorded yet today.</p>
                    ) : (
                        <table className="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Time</th>
                                    <th className="py-2">Direction</th>
                                    <th className="py-2">Source</th>
                                    <th className="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {punches.map((p) => (
                                    <tr key={p.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 font-mono text-xs">{p.time}</td>
                                        <td className="py-2">{p.direction ?? '—'}</td>
                                        <td className="py-2 text-muted-foreground">{p.source}</td>
                                        <td className="py-2">
                                            {p.needs_review ? (
                                                <span className="text-xs text-muted-foreground">
                                                    <Badge variant="outline">Flagged</Badge>
                                                    {p.review_reason && <span className="ml-2">{p.review_reason}</span>}
                                                </span>
                                            ) : (
                                                <Badge variant="secondary">Recorded</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
