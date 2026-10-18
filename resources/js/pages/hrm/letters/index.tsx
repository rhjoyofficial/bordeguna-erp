import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * The letters issued to one employee, and the button that drafts the next.
 * Each letter is a frozen snapshot; reprinting never changes a word.
 */
interface LetterRow {
    id: number;
    reference_no: string;
    type: string;
    issued_on: string;
    issued_by: string | null;
}

interface LettersProps {
    employee: { id: number; employee_code: string; first_name: string; last_name: string };
    letters: LetterRow[];
}

const typeLabel: Record<string, string> = {
    appointment: 'Appointment letter',
    confirmation: 'Confirmation letter',
    increment: 'Increment letter',
    experience: 'Experience certificate',
};

export default function Letters({ employee, letters }: LettersProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: `${employee.first_name} ${employee.last_name}`, href: `/hrm/employees/${employee.id}` },
        { title: 'Letters', href: `/hrm/employees/${employee.id}/letters` },
    ];

    const form = useForm({ type: 'appointment' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Letters - ${employee.employee_code}`} />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        HR letters: {employee.first_name} {employee.last_name} ({employee.employee_code})
                    </h1>
                </div>

                <form
                    className="border-border bg-card flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/hrm/employees/${employee.id}/letters`, { preserveScroll: true });
                    }}
                >
                    <div className="min-w-56 space-y-1">
                        <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(typeLabel).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.type && <p className="text-destructive text-xs">{form.errors.type}</p>}
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Draft letter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Reference</th>
                                <th className="px-3 py-2 font-medium">Letter</th>
                                <th className="px-3 py-2 font-medium">Issued</th>
                                <th className="px-3 py-2 font-medium">By</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {letters.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-3 py-4 text-center">
                                        No letters issued yet.
                                    </td>
                                </tr>
                            ) : (
                                letters.map((letter) => (
                                    <tr key={letter.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{letter.reference_no}</td>
                                        <td className="px-3 py-2">{typeLabel[letter.type] ?? letter.type}</td>
                                        <td className="px-3 py-2">{showDate(letter.issued_on)}</td>
                                        <td className="px-3 py-2">{letter.issued_by}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/hrm/letters/${letter.id}/print`}>Open / print</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
