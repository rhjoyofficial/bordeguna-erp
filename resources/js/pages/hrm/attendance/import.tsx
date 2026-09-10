import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

interface ReportRow {
    row: number;
    status: string;
    reason: string | null;
    enroll_id: string | null;
}

interface Batch {
    id: number;
    filename: string;
    source: string;
    device_identifier: string | null;
    total_rows: number;
    imported_count: number;
    duplicate_count: number;
    failed_count: number;
    entirely_duplicate: boolean;
    report: ReportRow[];
    uploaded_by: string | null;
    uploaded_at: string | null;
}

interface ImportProps {
    batches: Batch[];
    sources: { value: string; label: string }[];
    unmapped_employees: { id: number; name: string; employee_code: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Punch log', href: '/hrm/attendance' },
    { title: 'Import', href: '/hrm/attendance/import' },
];

export default function AttendanceImport({ batches, sources, unmapped_employees }: ImportProps) {
    const [file, setFile] = useState<File | null>(null);
    const [source, setSource] = useState(sources[0]?.value ?? 'biometric');
    const [device, setDevice] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [expanded, setExpanded] = useState<number | null>(null);
    const [uploading, setUploading] = useState(false);

    const submit = () => {
        if (!file) return;
        setUploading(true);
        router.post(
            route('hrm.attendance.import.store'),
            { file, source, device_identifier: device === '' ? null : device },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setErrors({});
                    setFile(null);
                },
                onFinish: () => setUploading(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import attendance" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">Import attendance</h2>
                    <p className="text-muted-foreground text-sm">
                        Upload a ZKTeco / ZKTime.Net CSV export. Re-uploading the same file is safe - rows already captured are reported as
                        duplicates, never imported twice.
                    </p>
                </div>

                {/* One folded line instead of a card of chips - the warning
                    matters, the geometry did not (Joy: take less area). */}
                {unmapped_employees.length > 0 && (
                    <details className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        <summary className="cursor-pointer">
                            {unmapped_employees.length} employee{unmapped_employees.length === 1 ? '' : 's'} without a biometric enroll ID will be
                            skipped - open for who
                        </summary>
                        <p className="mt-1 text-xs">
                            Imported rows are matched on the device&apos;s enroll ID. Set it on the employee record:{' '}
                            {unmapped_employees.map((e) => `${e.name} (${e.employee_code})`).join(', ')}.
                        </p>
                    </details>
                )}

                {/* The whole form on one row on a desktop - file, source,
                    device, go - instead of a three-column card plus a button
                    row beneath it. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border grid items-end gap-3 rounded-xl border p-3 sm:grid-cols-[1.7fr_1fr_1fr_auto]">
                    <div className="grid gap-2">
                        <Label htmlFor="file" required>
                            Attendance file
                        </Label>
                        <Input
                            id="file"
                            type="file"
                            accept=".csv,.txt,.xls,.xlsx,.ods,text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                        <p className="text-muted-foreground text-xs">
                            Excel (.xls, .xlsx), OpenDocument (.ods) or CSV. Upload the device export as it comes.
                        </p>
                        {errors.file && <p className="text-destructive text-xs">{errors.file}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="source" required>
                            Source
                        </Label>
                        <SearchSelect id="source" value={source} onChange={(e) => setSource(e.target.value)}>
                            {sources.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="device">Device (optional)</Label>
                        <Input id="device" value={device} onChange={(e) => setDevice(e.target.value)} placeholder="e.g. K40-Gate-1" />
                        {errors.device_identifier && <p className="text-destructive text-xs">{errors.device_identifier}</p>}
                    </div>
                    <div>
                        <Button onClick={submit} disabled={!file || uploading}>
                            {uploading ? 'Importing…' : 'Import'}
                        </Button>
                    </div>
                    {/* The 23 Aug lesson is CLOSED (24 Aug): importing now
                        recomputes the imported days itself, so the screens
                        change immediately. The line stays to say so, and to
                        keep the nightly run's sweep-up role on record. */}
                    <p className="text-muted-foreground text-xs sm:col-span-4">
                        Importing recomputes the affected days automatically, so Daily attendance shows the result right away. The nightly run still
                        sweeps up anything else.
                    </p>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Import history</h3>
                    {batches.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing imported yet.</p>
                    ) : (
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">File</th>
                                    <th className="py-2">Source</th>
                                    <th className="py-2">Rows</th>
                                    <th className="py-2">Imported</th>
                                    <th className="py-2">Duplicate</th>
                                    <th className="py-2">Failed</th>
                                    <th className="py-2">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                {batches.map((batch) => (
                                    <Fragment key={batch.id}>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-2">
                                                {batch.filename}
                                                {batch.device_identifier && (
                                                    <span className="text-muted-foreground ml-2 text-xs">{batch.device_identifier}</span>
                                                )}
                                                {batch.entirely_duplicate && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        Already imported
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-2">{batch.source}</td>
                                            <td className="text-muted-foreground py-2">{batch.total_rows}</td>
                                            <td className="py-2">{batch.imported_count}</td>
                                            <td className="text-muted-foreground py-2">{batch.duplicate_count}</td>
                                            <td className="py-2">
                                                {batch.failed_count > 0 ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setExpanded(expanded === batch.id ? null : batch.id)}
                                                    >
                                                        {batch.failed_count} - {expanded === batch.id ? 'hide' : 'why?'}
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted-foreground">0</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-xs">
                                                {batch.uploaded_at}
                                                {batch.uploaded_by && <div>by {batch.uploaded_by}</div>}
                                            </td>
                                        </tr>
                                        {expanded === batch.id && (
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td colSpan={7} className="py-2">
                                                    <div className="max-h-64 overflow-y-auto text-xs">
                                                        {batch.report.length === 0 ? (
                                                            <p className="text-muted-foreground">No detail recorded.</p>
                                                        ) : (
                                                            batch.report.map((row, i) => (
                                                                <div key={i} className="border-sidebar-border/40 border-b py-1">
                                                                    <span className="font-mono">Row {row.row}</span>
                                                                    <span className="text-muted-foreground ml-2">{row.status}</span>
                                                                    {row.enroll_id && <span className="ml-2">enroll {row.enroll_id}</span>}
                                                                    <span className="ml-2">{row.reason}</span>
                                                                </div>
                                                            ))
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
