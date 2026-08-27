import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface EmployeeRow {
    id: number;
    employee_code: string;
    name: string;
    has_structure: boolean;
    effective_from: string | null;
    basic: number | null;
    gross: number | null;
}

interface Component {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    is_basic: boolean;
    calculation_basis: string;
    basis_label: string;
    is_percentage: boolean;
    sequence: number;
    is_statutory: boolean;
    description: string | null;
    is_active: boolean;
}

interface Props {
    can_manage: boolean;
    employees: EmployeeRow[];
    components: Component[];
    types: { value: string; label: string }[];
    bases: { value: string; label: string; allowed_for_earning: boolean; allowed_for_deduction: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Salary', href: '/hrm/salary' }];
const money = (n: number | null) => (n === null ? '-' : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

function ComponentDialog({
    component,
    bases,
    types,
    trigger,
}: {
    component?: Component;
    bases: Props['bases'];
    types: Props['types'];
    trigger: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [code, setCode] = useState(component?.code ?? '');
    const [name, setName] = useState(component?.name ?? '');
    const [type, setType] = useState(component?.type ?? 'earning');
    const [basis, setBasis] = useState(component?.calculation_basis ?? 'fixed');
    const [sequence, setSequence] = useState(String(component?.sequence ?? 100));
    const [description, setDescription] = useState(component?.description ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Gross is the sum of the earnings, so an earning cannot be a share of it.
    // And a per-day allowance is earned by attending, so it cannot be charged
    // as a deduction (C14). Both rules are re-checked server-side.
    const available = bases.filter((b) => (type === 'earning' ? b.allowed_for_earning : b.allowed_for_deduction));

    const submit = () => {
        const payload = { code, name, type, calculation_basis: basis, sequence: Number(sequence), description: description || null };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => {
                setOpen(false);
                setErrors({});
            },
        };
        if (component) router.put(route('hrm.salary.components.update', component.id), payload, options);
        else router.post(route('hrm.salary.components.store'), payload, options);
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{component ? 'Edit component' : 'New salary component'}</DialogTitle>
                    <DialogDescription>
                        Components are defined once and reused across every employee, so a payslip line always traces back to a definition.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                                placeholder="house_rent"
                                disabled={component?.is_basic}
                            />
                            {errors.code && <p className="text-destructive text-xs">{errors.code}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={name} onChange={(e) => setName(e.target.value)} />
                            {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <SearchSelect
                                id="type"
                                value={type}
                                disabled={component?.is_basic}
                                onChange={(e) => {
                                    const next = e.target.value;
                                    setType(next);
                                    // Drop a basis the new type cannot use, or the
                                    // select would keep showing a value that is no
                                    // longer in its option list and submit it.
                                    const stillAllowed = bases.find(
                                        (b) => b.value === basis && (next === 'earning' ? b.allowed_for_earning : b.allowed_for_deduction),
                                    );
                                    if (!stillAllowed) setBasis('fixed');
                                }}
                            >
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="basis">Calculated as</Label>
                            <SearchSelect id="basis" value={basis} disabled={component?.is_basic} onChange={(e) => setBasis(e.target.value)}>
                                {available.map((b) => (
                                    <option key={b.value} value={b.value}>
                                        {b.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.calculation_basis && <p className="text-destructive text-xs">{errors.calculation_basis}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sequence">Order</Label>
                            <Input id="sequence" type="number" value={sequence} onChange={(e) => setSequence(e.target.value)} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="description">Note (optional)</Label>
                        <Input id="description" value={description} onChange={(e) => setDescription(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={code.trim() === '' || name.trim() === ''}>
                        {component ? 'Save' : 'Create'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SalaryIndex({ can_manage, employees, components, types, bases }: Props) {
    const withStructure = employees.filter((e) => e.has_structure).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Salary" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Salary structures</h2>
                        <p className="text-muted-foreground text-sm">
                            {withStructure} of {employees.length} employees have a structure. These are contractual figures - what a month actually
                            pays is calculated by payroll.
                        </p>
                    </div>
                    {can_manage && <ComponentDialog bases={bases} types={types} trigger={<Button size="sm">New component</Button>} />}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Employees</h3>
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">Employee</th>
                                <th className="py-2">Effective from</th>
                                <th className="py-2 text-right">Basic</th>
                                <th className="py-2 text-right">Gross</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {employees.map((e) => (
                                <tr key={e.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {e.name}
                                        <span className="text-muted-foreground ml-2 text-xs">{e.employee_code}</span>
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {e.has_structure ? e.effective_from : <Badge variant="outline">No structure</Badge>}
                                    </td>
                                    <td className="py-2 text-right">{money(e.basic)}</td>
                                    <td className="py-2 text-right font-medium">{money(e.gross)}</td>
                                    <td className="py-2 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('hrm.salary.show', e.id)}>{can_manage ? 'Manage' : 'View'}</Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Components</h3>
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">Component</th>
                                <th className="py-2">Type</th>
                                <th className="py-2">Calculated as</th>
                                <th className="py-2">Note</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {components.map((c) => (
                                <tr key={c.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {c.name}
                                        {c.is_basic && (
                                            <Badge variant="default" className="ml-2">
                                                Basic
                                            </Badge>
                                        )}
                                        {c.is_statutory && (
                                            <Badge variant="outline" className="ml-2">
                                                Statutory
                                            </Badge>
                                        )}
                                        {!c.is_active && (
                                            <Badge variant="secondary" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">{c.type_label}</td>
                                    <td className="text-muted-foreground py-2">{c.basis_label}</td>
                                    <td className="text-muted-foreground py-2 text-xs">{c.description ?? '-'}</td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        {can_manage && (
                                            <>
                                                <ComponentDialog
                                                    component={c}
                                                    bases={bases}
                                                    types={types}
                                                    trigger={
                                                        <Button variant="ghost" size="sm">
                                                            Edit
                                                        </Button>
                                                    }
                                                />
                                                {!c.is_basic && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.patch(route('hrm.salary.components.toggle', c.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        {c.is_active ? 'Deactivate' : 'Activate'}
                                                    </Button>
                                                )}
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="text-muted-foreground mt-3 text-xs">
                        Income Tax and Provident Fund are entered manually - the statutory calculation rules (GOV-06) are not yet decided, so nothing
                        is computed for them automatically.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
