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

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

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
                    <p className="text-sm text-muted-foreground">
                        Upload a ZKTeco / ZKTime.Net CSV export. Re-uploading the same file is safe — rows already captured are reported as duplicates,
                        never imported twice.
                    </p>
                </div>

                {unmapped_employees.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <h3 className="text-sm font-medium">
                            {unmapped_employees.length} employee{unmapped_employees.length === 1 ? '' : 's'} without a biometric enroll ID
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Imported rows are matched on the device&apos;s enroll ID, so these employees will be skipped. Set it on the employee record.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {unmapped_employees.map((e) => (
                                <Badge key={e.id} variant="secondary">
                                    {e.name} ({e.employee_code})
                                </Badge>
                            ))}
                        </div>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-xl border p-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="file">Attendance file</Label>
                        <Input
                            id="file"
                            type="file"
                            accept=".csv,.txt,.xls,.xlsx,.ods,text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Excel (.xls, .xlsx), OpenDocument (.ods) or CSV. Upload the device export as it comes.
                        </p>
                        {errors.file && <p className="text-xs text-destructive">{errors.file}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="source">Source</Label>
                        <select id="source" className={selectClass} value={source} onChange={(e) => setSource(e.target.value)}>
                            {sources.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="device">Device (optional)</Label>
                        <Input id="device" value={device} onChange={(e) => setDevice(e.target.value)} placeholder="e.g. K40-Gate-1" />
                        {errors.device_identifier && <p className="text-xs text-destructive">{errors.device_identifier}</p>}
                    </div>
                    <div className="sm:col-span-3">
                        <Button onClick={submit} disabled={!file || uploading}>
                            {uploading ? 'Importing…' : 'Import'}
                        </Button>
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Import history</h3>
                    {batches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nothing imported yet.</p>
                    ) : (
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
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
                                                    <span className="ml-2 text-xs text-muted-foreground">{batch.device_identifier}</span>
                                                )}
                                                {batch.entirely_duplicate && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        Already imported
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 text-muted-foreground">{batch.source}</td>
                                            <td className="py-2 text-muted-foreground">{batch.total_rows}</td>
                                            <td className="py-2">{batch.imported_count}</td>
                                            <td className="py-2 text-muted-foreground">{batch.duplicate_count}</td>
                                            <td className="py-2">
                                                {batch.failed_count > 0 ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setExpanded(expanded === batch.id ? null : batch.id)}
                                                    >
                                                        {batch.failed_count} — {expanded === batch.id ? 'hide' : 'why?'}
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted-foreground">0</span>
                                                )}
                                            </td>
                                            <td className="py-2 text-xs text-muted-foreground">
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
                                                                    <span className="ml-2 text-muted-foreground">{row.status}</span>
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
