import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

export interface Inspection {
    id: number;
    inspected_on: string | null;
    notes: string | null;
    by: string | null;
    photos: { id: number; name: string; url: string }[];
}

/**
 * What the ASM saw at a distributor (Joy, answer 17): optional, and nothing
 * waits for one. Shown beside the distributor's own claims, never merged with
 * them. Append-only, so the list has no edit or delete.
 *
 * ⚠ Rendered for a distributor lead, or for any lead that already carries an
 * inspection (a type corrected later keeps its history). A NEW inspection is
 * offered on a distributor only, and only to the ASM.
 */
export function LeadInspections({
    leadId,
    leadType,
    inspections,
    canInspect,
    today,
}: {
    leadId: number;
    leadType: string | null;
    inspections: Inspection[];
    canInspect: boolean;
    today: string;
}) {
    const photoInput = useRef<HTMLInputElement>(null);
    const form = useForm<{ inspected_on: string; notes: string; photos: File[] }>({ inspected_on: today, notes: '', photos: [] });

    if (leadType !== 'distributor' && inspections.length === 0) {
        return null;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/crm/leads/${leadId}/inspections`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                if (photoInput.current) photoInput.current.value = '';
            },
        });
    };

    const photoError = Object.entries(form.errors).find(([key]) => key.startsWith('photos'))?.[1];

    return (
        <div className="flex flex-col gap-3">
            <h2 className="text-sm font-medium">Inspections</h2>

            {canInspect && leadType === 'distributor' && (
                <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-4">
                    <p className="text-sm font-medium">Record what you saw</p>
                    <div className="grid gap-2 sm:w-48">
                        <Label htmlFor="inspected_on" required>
                            Visited on
                        </Label>
                        <Input
                            id="inspected_on"
                            aria-required
                            type="date"
                            max={today}
                            value={form.data.inspected_on}
                            onChange={(e) => form.setData('inspected_on', e.target.value)}
                        />
                        <InputError message={form.errors.inspected_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="inspection_notes">What you saw</Label>
                        <textarea
                            id="inspection_notes"
                            className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                            placeholder="Godowns, stock on hand, vehicles in the yard, staff"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                        />
                        <InputError message={form.errors.notes} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="inspection_photos">Photos (up to 6)</Label>
                        <input
                            ref={photoInput}
                            id="inspection_photos"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            className="w-full min-w-0 text-sm"
                            onChange={(e) => form.setData('photos', Array.from(e.target.files ?? []))}
                        />
                        <InputError message={photoError} />
                    </div>
                    {/* The type refusal is keyed outside the form's own fields. */}
                    <InputError message={(form.errors as Record<string, string | undefined>).lead_type} />
                    <div>
                        <Button disabled={form.processing}>Record inspection</Button>
                    </div>
                </form>
            )}

            <div className="flex flex-col gap-2">
                {inspections.map((i) => (
                    <div key={i.id} className="rounded-md border p-3 text-sm">
                        <div className="flex items-start justify-between gap-3">
                            <p className="font-medium">Visited {i.inspected_on}</p>
                            {i.by && <span className="text-muted-foreground shrink-0 text-xs">{i.by}</span>}
                        </div>
                        {i.notes && <p className="mt-1 whitespace-pre-wrap">{i.notes}</p>}
                        {i.photos.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {i.photos.map((p) => (
                                    <a key={p.id} href={p.url} target="_blank" rel="noreferrer" title={p.name}>
                                        <img src={p.url} alt={p.name} loading="lazy" className="h-20 w-20 rounded border object-cover" />
                                    </a>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
                {inspections.length === 0 && <p className="text-muted-foreground text-sm">No inspection recorded. None is required.</p>}
            </div>
        </div>
    );
}
