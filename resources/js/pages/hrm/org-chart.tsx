import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The organization chart (Module 1, Slice 1): the reporting tree, hand-rolled
 * with CSS connectors like every other graphic in this app - no chart library.
 *
 * A person with no recorded line sits at the root beside the founder; the
 * `unplaced` count at the top tells HR whether the chart is finished or still
 * being collected from the data pack.
 */
interface ChartNode {
    id: number;
    name: string;
    code: string;
    designation: string | null;
    reports: ChartNode[];
}

interface OrgChartProps {
    tree: ChartNode[];
    unplaced: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Organization chart', href: '/hrm/org-chart' }];

function PersonCard({ node }: { node: ChartNode }) {
    return (
        <div className="border-border bg-card inline-block rounded-lg border px-4 py-2.5 text-left shadow-sm">
            <p className="text-sm leading-tight font-semibold">{node.name}</p>
            <p className="text-muted-foreground text-xs leading-tight">
                {node.designation ?? 'No designation'} · {node.code}
            </p>
        </div>
    );
}

/**
 * One branch: the person, then a rail down the left with their reports
 * indented under it. Nested lists survive any depth and print sensibly,
 * which a canvas-drawn chart never does.
 */
function Branch({ node, depth }: { node: ChartNode; depth: number }) {
    return (
        <li className="relative">
            <PersonCard node={node} />
            {node.reports.length > 0 && (
                <ul className="border-border mt-2 ml-5 space-y-2 border-l-2 pl-5">
                    {node.reports.map((report) => (
                        <Branch key={report.id} node={report} depth={depth + 1} />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function OrgChart({ tree, unplaced }: OrgChartProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organization chart" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Organization chart</h1>
                    </div>
                    {unplaced > 0 && (
                        <p className="text-muted-foreground rounded-md border border-dashed px-3 py-1.5 text-xs">
                            {unplaced} {unplaced === 1 ? 'person has' : 'people have'} no reporting line recorded yet - set it under{' '}
                            <Link href="/hrm/employees" className="underline">
                                Employees
                            </Link>
                            .
                        </p>
                    )}
                </div>

                {tree.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No active employees to chart.</p>
                ) : (
                    <ul className="space-y-4">
                        {tree.map((root) => (
                            <Branch key={root.id} node={root} depth={0} />
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
