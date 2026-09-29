import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

/**
 * Performance phase 1 (Module 5, Slice 5): goals with outcomes, one review
 * per period. Nothing here touches pay.
 */
interface Goal {
    id: number;
    title: string;
    description: string | null;
    due_on: string | null;
    status: string;
    set_by: string | null;
    decided_by: string | null;
    decided_at: string | null;
}

interface Review {
    id: number;
    period: string;
    rating: number;
    strengths: string | null;
    improvements: string | null;
    reviewed_by: string | null;
}

interface PerformanceProps {
    employee: { id: number; employee_code: string; name: string };
    goals: Goal[];
    reviews: Review[];
}

export default function Performance({ employee, goals, reviews }: PerformanceProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.name, href: `/hrm/employees/${employee.id}` },
        { title: 'Performance', href: `/hrm/employees/${employee.id}/performance` },
    ];

    const goalForm = useForm({ title: '', due_on: '' });
    const reviewForm = useForm({ period: '', rating: '3', strengths: '', improvements: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Performance - ${employee.employee_code}`} />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        Performance: {employee.name} ({employee.employee_code})
                    </h1>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="border-border bg-card space-y-3 rounded-lg border p-4">
                        <h2 className="font-semibold">Goals</h2>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                goalForm.post(`/hrm/employees/${employee.id}/goals`, {
                                    preserveScroll: true,
                                    onSuccess: () => goalForm.reset(),
                                });
                            }}
                        >
                            <div className="min-w-56 flex-1 space-y-1">
                                <Label className="text-xs" required>
                                    New goal
                                </Label>
                                <Input
                                    placeholder="e.g. Open five new dealer accounts"
                                    value={goalForm.data.title}
                                    onChange={(e) => goalForm.setData('title', e.target.value)}
                                />
                                {goalForm.errors.title && <p className="text-destructive text-xs">{goalForm.errors.title}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Due</Label>
                                <Input type="date" value={goalForm.data.due_on} onChange={(e) => goalForm.setData('due_on', e.target.value)} />
                            </div>
                            <Button type="submit" disabled={goalForm.processing}>
                                Set
                            </Button>
                        </form>

                        <div className="space-y-2">
                            {goals.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No goals yet.</p>
                            ) : (
                                goals.map((goal) => (
                                    <div key={goal.id} className="rounded-md border p-2.5 text-sm">
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className={goal.status !== 'open' ? 'text-muted-foreground' : 'font-medium'}>{goal.title}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {goal.due_on && `due ${goal.due_on} · `}set by {goal.set_by}
                                                    {goal.status !== 'open' && ` · ${goal.status} by ${goal.decided_by}`}
                                                </p>
                                            </div>
                                            {goal.status === 'open' ? (
                                                <span className="flex gap-1">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        className="h-7 bg-emerald-600 text-white hover:bg-emerald-700"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hrm/goals/${goal.id}/outcome`,
                                                                { status: 'achieved' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Achieved
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive h-7"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hrm/goals/${goal.id}/outcome`,
                                                                { status: 'missed' },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Missed
                                                    </Button>
                                                </span>
                                            ) : (
                                                <span
                                                    className={
                                                        'rounded-full px-2 py-0.5 text-xs font-medium ' +
                                                        (goal.status === 'achieved'
                                                            ? 'bg-emerald-500/10 text-emerald-700'
                                                            : 'bg-destructive/10 text-destructive')
                                                    }
                                                >
                                                    {goal.status}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    <div className="border-border bg-card space-y-3 rounded-lg border p-4">
                        <h2 className="font-semibold">Reviews</h2>
                        <form
                            className="grid gap-2 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                reviewForm.post(`/hrm/employees/${employee.id}/reviews`, { preserveScroll: true });
                            }}
                        >
                            <div className="space-y-1">
                                <Label className="text-xs" required>
                                    Period
                                </Label>
                                <Input
                                    placeholder="e.g. 2026-Q3 (same period replaces)"
                                    value={reviewForm.data.period}
                                    onChange={(e) => reviewForm.setData('period', e.target.value)}
                                />
                                {reviewForm.errors.period && <p className="text-destructive text-xs">{reviewForm.errors.period}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs" required>
                                    Rating
                                </Label>
                                <Select value={reviewForm.data.rating} onValueChange={(value) => reviewForm.setData('rating', value)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['1', '2', '3', '4', '5'].map((rating) => (
                                            <SelectItem key={rating} value={rating}>
                                                {rating} / 5
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Strengths</Label>
                                <Input value={reviewForm.data.strengths} onChange={(e) => reviewForm.setData('strengths', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">To improve</Label>
                                <Input value={reviewForm.data.improvements} onChange={(e) => reviewForm.setData('improvements', e.target.value)} />
                            </div>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={reviewForm.processing}>
                                    Save review
                                </Button>
                            </div>
                        </form>

                        <div className="space-y-2">
                            {reviews.map((review) => (
                                <div key={review.id} className="rounded-md border p-2.5 text-sm">
                                    <p className="font-medium">
                                        {review.period} · {review.rating}/5
                                        <span className="text-muted-foreground text-xs font-normal"> · by {review.reviewed_by}</span>
                                    </p>
                                    {review.strengths && <p className="text-muted-foreground text-xs">Strengths: {review.strengths}</p>}
                                    {review.improvements && <p className="text-muted-foreground text-xs">Improve: {review.improvements}</p>}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
