import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface Option {
    value: string | number;
    label: string;
}

interface Parameter {
    key: string;
    label: string;
    type: string;
    required: boolean;
    options: Option[];
    help: string | null;
    default: string | number | boolean | null;
}

interface Column {
    key: string;
    label: string;
    type: string;
    align: string;
}

interface Format {
    key: string;
    label: string;
    download: boolean;
}

interface Props {
    report: { key: string; name: string; description: string; group: string };
    parameters: Parameter[];
    columns: Column[];
    formats: Format[];
    queue_threshold: number;
}

/**
 * The generic parameter screen. It knows nothing about any particular report —
 * the server declares the parameters and this renders them, which is what lets
 * fifteen reports share one page.
 *
 * The run is a plain GET navigation rather than an Inertia visit, because the
 * response is a file. An Inertia visit expects a page back and would swallow
 * the download.
 */
export default function ReportShow({ report, parameters, columns, formats, queue_threshold }: Props) {
    const initial = useMemo(() => {
        const values: Record<string, string> = {};

        parameters.forEach((parameter) => {
            if (parameter.type === 'date_range') {
                values[`${parameter.key}_from`] = '';
                values[`${parameter.key}_to`] = '';
                return;
            }

            values[parameter.key] = parameter.default === null || parameter.default === false ? '' : String(parameter.default);
        });

        return values;
    }, [parameters]);

    const [values, setValues] = useState<Record<string, string>>(initial);
    const [errors, setErrors] = useState<string[]>([]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HRM', href: '/hrm/employees' },
        { title: 'Reports', href: '/hrm/reports' },
        { title: report.name, href: `/hrm/reports/${report.key}` },
    ];

    const set = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));

    const missing = () =>
        parameters
            .filter((parameter) => {
                if (!parameter.required) return false;

                if (parameter.type === 'date_range') {
                    return !values[`${parameter.key}_from`] || !values[`${parameter.key}_to`];
                }

                return !values[parameter.key];
            })
            .map((parameter) => parameter.label);

    const run = (format: Format) => {
        const absent = missing();

        if (absent.length > 0) {
            setErrors(absent);
            return;
        }

        setErrors([]);

        const query = new URLSearchParams();
        Object.entries(values).forEach(([key, value]) => {
            if (value !== '') query.append(key, value);
        });
        query.append('format', format.key);

        const url = `/hrm/reports/${report.key}/run?${query.toString()}`;

        // A print view opens in its own tab so the browser's print dialog has
        // somewhere to belong; a download stays in this one.
        if (format.download) {
            window.location.href = url;
        } else {
            window.open(url, '_blank', 'noopener');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={report.name} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{report.name}</h1>
                    <p className="text-muted-foreground text-sm">{report.description}</p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 rounded-lg border p-4 lg:col-span-2">
                        <h2 className="text-sm font-semibold">Filters</h2>

                        <div className="grid gap-4 md:grid-cols-2">
                            {parameters.map((parameter) => (
                                <div key={parameter.key} className="flex flex-col gap-1.5">
                                    <Label htmlFor={parameter.key}>
                                        {parameter.label}
                                        {parameter.required && <span className="text-destructive"> *</span>}
                                    </Label>

                                    {parameter.type === 'date' && (
                                        <Input
                                            id={parameter.key}
                                            type="date"
                                            value={values[parameter.key] ?? ''}
                                            onChange={(e) => set(parameter.key, e.target.value)}
                                        />
                                    )}

                                    {parameter.type === 'date_range' && (
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="date"
                                                value={values[`${parameter.key}_from`] ?? ''}
                                                onChange={(e) => set(`${parameter.key}_from`, e.target.value)}
                                            />
                                            <span className="text-muted-foreground text-sm">to</span>
                                            <Input
                                                type="date"
                                                value={values[`${parameter.key}_to`] ?? ''}
                                                onChange={(e) => set(`${parameter.key}_to`, e.target.value)}
                                            />
                                        </div>
                                    )}

                                    {parameter.type === 'boolean' && (
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={values[parameter.key] === '1'}
                                                onChange={(e) => set(parameter.key, e.target.checked ? '1' : '')}
                                            />
                                            Yes
                                        </label>
                                    )}

                                    {['office', 'department', 'employee', 'payroll_run', 'period', 'select'].includes(parameter.type) && (
                                        <select
                                            id={parameter.key}
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                            value={values[parameter.key] ?? ''}
                                            onChange={(e) => set(parameter.key, e.target.value)}
                                        >
                                            <option value="">{parameter.required ? 'Select…' : 'All'}</option>
                                            {parameter.options.map((option) => (
                                                <option key={String(option.value)} value={String(option.value)}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}

                                    {parameter.help && <p className="text-muted-foreground text-xs">{parameter.help}</p>}
                                </div>
                            ))}
                        </div>

                        {errors.length > 0 && (
                            <p className="text-destructive text-sm">Please choose: {errors.join(', ')}.</p>
                        )}

                        <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                            {formats.map((format) => (
                                <Button key={format.key} variant={format.key === 'xlsx' ? 'default' : 'outline'} onClick={() => run(format)}>
                                    {format.label}
                                </Button>
                            ))}
                        </div>

                        <p className="text-muted-foreground text-xs">
                            A report estimated over {queue_threshold.toLocaleString()} rows is prepared in the background and emailed to you
                            when it is ready.
                        </p>
                    </div>

                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <h2 className="text-sm font-semibold">Columns</h2>
                        <ul className="text-muted-foreground flex flex-col gap-1 text-sm">
                            {columns.map((column) => (
                                <li key={column.key} className="flex items-center justify-between gap-2">
                                    <span>{column.label}</span>
                                    <span className="text-xs opacity-60">{column.type}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
