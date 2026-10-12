import InputError from '@/components/input-error';
import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export const textareaClass = 'border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm';

export type DoneValues = {
    done_on: string;
    work_done: string;
    condition_after: string;
    who: string;
    cost: string;
    expense_voucher_id: string;
};

export const emptyDone = (today: string): DoneValues => ({
    done_on: today,
    work_done: '',
    condition_after: '',
    who: '',
    cost: '',
    expense_voucher_id: '',
});

/**
 * "Who did it" is one picker over people and outside parties; the server
 * takes them as two fields.
 */
export function whoPayload(who: string): { done_by_user_id: string | null; party_id: string | null } {
    return {
        done_by_user_id: who.startsWith('u:') ? who.slice(2) : null,
        party_id: who.startsWith('p:') ? who.slice(2) : null,
    };
}

export function whoOptions(users: SelectOption[], parties: SelectOption[]): SelectOption[] {
    return [
        { value: '', label: 'Not said (the keeper)' },
        ...users.map((user) => ({ value: `u:${user.value}`, label: user.label })),
        ...parties.map((party) => ({ value: `p:${party.value}`, label: party.label })),
    ];
}

/**
 * What was done, by whom, and (for the financial register only) what it cost
 * and which voucher paid it (slice A4).
 */
export function DoneFields({
    values,
    set,
    errors,
    users,
    parties,
    conditions,
    vouchers,
    seesMoney,
    today,
}: {
    values: DoneValues;
    set: (field: keyof DoneValues, value: string) => void;
    errors: Partial<Record<string, string>>;
    users: SelectOption[];
    parties: SelectOption[];
    conditions: SelectOption[];
    vouchers: SelectOption[];
    seesMoney: boolean;
    today: string;
}) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor="done_on" required>
                    Done on
                </Label>
                <Input id="done_on" type="date" aria-required max={today} value={values.done_on} onChange={(e) => set('done_on', e.target.value)} />
                <InputError message={errors.done_on} />
            </div>
            <div className="grid gap-2">
                <Label>Who did it</Label>
                <SearchableSelect value={values.who} onChange={(v) => set('who', v)} options={whoOptions(users, parties)} />
                <InputError message={errors.done_by_user_id ?? errors.party_id} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="work_done" required>
                    What was done
                </Label>
                <textarea
                    id="work_done"
                    aria-required
                    className={textareaClass}
                    value={values.work_done}
                    onChange={(e) => set('work_done', e.target.value)}
                />
                <InputError message={errors.work_done} />
            </div>
            <div className="grid gap-2">
                <Label>Condition afterwards</Label>
                <SearchableSelect
                    value={values.condition_after}
                    onChange={(v) => set('condition_after', v)}
                    options={[{ value: '', label: 'Unchanged' }, ...conditions]}
                />
                <InputError message={errors.condition_after} />
            </div>
            {seesMoney && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="cost">What it cost</Label>
                        <Input id="cost" type="number" min={0} step="0.01" value={values.cost} onChange={(e) => set('cost', e.target.value)} />
                        <InputError message={errors.cost} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label>Paid by expense voucher</Label>
                        <SearchableSelect
                            value={values.expense_voucher_id}
                            onChange={(v) => set('expense_voucher_id', v)}
                            options={[{ value: '', label: 'Not paid yet, or not by voucher' }, ...vouchers]}
                        />
                        <InputError message={errors.expense_voucher_id} />
                    </div>
                </>
            )}
        </div>
    );
}
