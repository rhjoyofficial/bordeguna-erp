import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export interface Sample {
    id: number;
    received_on: string | null;
    item: string;
    note: string | null;
    by: string | null;
    result: string | null;
    result_label: string | null;
    result_note: string | null;
    judged_by: string | null;
    judged_at: string | null;
}

type ValueLabel = { value: string; label: string };

const RESULT_BADGE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pass: 'default',
    fail: 'destructive',
    retest: 'secondary',
};

/**
 * Samples a supplier sent, and the Owner's verdicts (Joy, answer 15; plan D13).
 *
 * ⚠ Whoever works the supplier logs what arrived; only the Owner says whether it
 * passed, once. A re-test is a new sample, so the list is the whole history of
 * verdicts, newest first.
 */
export function LeadSamples({
    leadId,
    leadType,
    samples,
    sampleResults,
    canRecord,
    canJudge,
    today,
}: {
    leadId: number;
    leadType: string | null;
    samples: Sample[];
    sampleResults: ValueLabel[];
    canRecord: boolean;
    canJudge: boolean;
    today: string;
}) {
    const form = useForm({ received_on: today, item: '', note: '' });

    if (leadType !== 'supplier' && samples.length === 0) {
        return null;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/crm/leads/${leadId}/samples`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="flex flex-col gap-3">
            <h2 className="text-sm font-medium">Samples</h2>

            {canRecord && leadType === 'supplier' && (
                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                >
                    <p className="w-full text-sm font-medium">A sample arrived</p>
                    <div className="grid gap-2">
                        <Label htmlFor="received_on" required>
                            Received on
                        </Label>
                        <Input
                            id="received_on"
                            aria-required
                            type="date"
                            max={today}
                            value={form.data.received_on}
                            onChange={(e) => form.setData('received_on', e.target.value)}
                        />
                        <InputError message={form.errors.received_on} />
                    </div>
                    <div className="grid w-full gap-2 sm:w-64">
                        <Label htmlFor="sample_item" required>
                            What
                        </Label>
                        <Input
                            id="sample_item"
                            aria-required
                            placeholder="Mustard seed, 1 kg"
                            value={form.data.item}
                            onChange={(e) => form.setData('item', e.target.value)}
                        />
                        <InputError message={form.errors.item} />
                    </div>
                    <div className="grid w-full gap-2 sm:w-64">
                        <Label htmlFor="sample_note">Note</Label>
                        <Input id="sample_note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    </div>
                    {/* The type refusal is keyed outside the form's own fields. */}
                    <InputError message={(form.errors as Record<string, string | undefined>).lead_type} />
                    <Button disabled={form.processing}>Record sample</Button>
                </form>
            )}

            <div className="flex flex-col gap-2">
                {samples.map((s) => (
                    <div key={s.id} className="rounded-md border p-3 text-sm">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-medium">{s.item}</p>
                                <p className="text-muted-foreground">
                                    received {s.received_on}
                                    {s.by && ` · ${s.by}`}
                                    {s.note && ` · ${s.note}`}
                                </p>
                            </div>
                            {s.result ? (
                                <Badge variant={RESULT_BADGE[s.result] ?? 'outline'}>{s.result_label}</Badge>
                            ) : (
                                <Badge variant="outline">Awaiting the Owner</Badge>
                            )}
                        </div>
                        {s.result && (
                            <p className="text-muted-foreground mt-1 text-xs">
                                {s.judged_by ?? 'Owner'} · {s.judged_at}
                                {s.result_note && ` · ${s.result_note}`}
                            </p>
                        )}
                        {!s.result && canJudge && <Verdict sampleId={s.id} results={sampleResults} />}
                    </div>
                ))}
                {samples.length === 0 && <p className="text-muted-foreground text-sm">No sample received yet.</p>}
            </div>
        </div>
    );
}

function Verdict({ sampleId, results }: { sampleId: number; results: ValueLabel[] }) {
    const form = useForm({ result: '', result_note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/crm/samples/${sampleId}/result`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2 border-t pt-3">
            <div className="grid gap-1">
                <Label htmlFor={`result_${sampleId}`} required>
                    Verdict
                </Label>
                <SearchSelect fit id={`result_${sampleId}`} value={form.data.result} onChange={(e) => form.setData('result', e.target.value)}>
                    <option value="">Choose…</option>
                    {results.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </SearchSelect>
            </div>
            <div className="grid w-full gap-1 sm:w-64">
                <Label htmlFor={`result_note_${sampleId}`}>Why</Label>
                <Input id={`result_note_${sampleId}`} value={form.data.result_note} onChange={(e) => form.setData('result_note', e.target.value)} />
            </div>
            <Button size="sm" disabled={form.processing || form.data.result === ''}>
                Record verdict
            </Button>
            <InputError message={form.errors.result} />
        </form>
    );
}
