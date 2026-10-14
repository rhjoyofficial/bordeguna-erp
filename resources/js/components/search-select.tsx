import { blankCounterparty, CounterpartyDialog } from '@/components/counterparty-dialog';
import { EntityQuickCreate, type QuickCreateKind } from '@/components/entity-quick-create';
import { LedgerQuickCreate, type LedgerType } from '@/components/ledger-quick-create';
import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Children, isValidElement, useState, type ReactNode } from 'react';

/**
 * A searchable picker that is drop-in compatible with the native <select> it
 * replaces: same <option> children, same onChange event shape.
 *
 * ⚠⚠ WHY THE NATIVE SHAPE AND NOT A CLEAN API. There were 169 native selects
 * across 63 screens. A component with a nicer signature would have meant
 * hand-rewriting every one of them, including labels built from conditional
 * JSX, days before cutover. Accepting `<option>` children and handing back a
 * `{ target: { value } }` event makes the migration a tag rename, which is
 * reviewable and cannot silently change what an option says. New code may use
 * SearchableSelect directly with a real options array.
 *
 * ⚠⚠ AN <option value=""> STAYS A PICKABLE ROW and also becomes the
 * placeholder text. It is tempting to treat it as placeholder only, but on a
 * filter that empty option is how the user gets back to "All"; hiding it would
 * strand them on whatever they picked first.
 *
 * ⚠ Option labels are flattened to text. `{t.name}{cond ? ' (a)' : ' (b)'}`
 * becomes the string a user would have seen. Anything that is not text (an
 * icon element inside an option) is dropped, which is what the native select
 * did with it anyway.
 */

/** The visible text of an option's children, however deeply the JSX nests. */
function textOf(node: ReactNode): string {
    if (node === null || node === undefined || typeof node === 'boolean') {
        return '';
    }

    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }

    if (Array.isArray(node)) {
        return node.map(textOf).join('');
    }

    if (isValidElement<{ children?: ReactNode }>(node)) {
        return textOf(node.props.children);
    }

    return '';
}

type OptionLike = {
    value?: string | number;
    disabled?: boolean;
    children?: ReactNode;
    /** `<option data-hint="...">`: a smaller second line under the label (see SelectOption.hint). */
    'data-hint'?: string | null;
    /** `<option data-heading>`: a bold group title that cannot be chosen. */
    'data-heading'?: boolean;
};

/** Walks children, flattening fragments and mapped arrays, collecting <option>s. */
function collect(children: ReactNode, into: { options: SelectOption[]; placeholder?: string }): void {
    Children.forEach(children, (child) => {
        if (!isValidElement(child)) {
            return;
        }

        // A fragment or an <optgroup>: recurse. Group headings are not rendered
        // as separators - cmdk groups on its own terms - but their options are
        // kept so nothing disappears from the list.
        if (child.type !== 'option') {
            collect((child.props as { children?: ReactNode }).children, into);

            return;
        }

        const props = child.props as OptionLike;
        const value = props.value === undefined ? '' : String(props.value);
        const label = textOf(props.children).trim();

        if (value === '') {
            into.placeholder = label;
        }

        into.options.push({
            value,
            label,
            disabled: props.disabled,
            hint: props['data-hint'] || undefined,
            heading: props['data-heading'] || undefined,
        });
    });
}

export function SearchSelect({
    children,
    value,
    onChange,
    id,
    name,
    className,
    disabled = false,
    invalid = false,
    searchThreshold,
    searchPlaceholder,
    emptyText,
    fit,
    newLedger,
    quickCreate,
    newCounterparty,
    required,
}: {
    children: ReactNode;
    value: string | number;
    /** The native handler shape, so existing `(e) => setX(e.target.value)` keeps working. */
    onChange: (event: { target: { value: string; name: string; id: string } }) => void;
    id?: string;
    name?: string;
    className?: string;
    disabled?: boolean;
    invalid?: boolean;
    searchThreshold?: number;
    searchPlaceholder?: string;
    emptyText?: string;
    /** Size to the option rather than to the column. See SearchableSelect. */
    fit?: boolean;
    /**
     * Marks this as a LEDGER picker: a "New ledger head" row appears for anyone
     * who may change the chart. `types` is what the list holds; `reload` names
     * the page prop(s) that feed it, re-fetched after a head is added.
     */
    newLedger?: { types: LedgerType[]; reload: string[] };
    /**
     * ⭐ 27 Sep 2026 (Joy): marks this as a picker that can create its own kind
     * on the fly (unit, category, brand, supplier). A "+ New …" row appears;
     * `reload` names the page prop(s) that feed the list, re-fetched after one is
     * added. The server enforces the permission, so a user who lacks it gets a
     * clear message rather than a silently missing row.
     */
    quickCreate?: { kind: QuickCreateKind; reload: string[]; createLabel?: string };
    /**
     * ⭐ 14 Oct 2026 (loans plan v2): a loan line's counterparty picker. A
     * "New counterparty" row opens the quick counterparty dialog for whoever
     * may keep the list; `related` ticks the related-party box ahead (on 11752);
     * `reload` names the page prop(s) that feed the list.
     */
    newCounterparty?: { reload: string[]; label: string; related?: boolean };
    /** Announced to screen readers; pair it with <Label required>. */
    required?: boolean;
}) {
    const { canManageChart, canManageCounterparties } = usePage<SharedData>().props;
    const [creating, setCreating] = useState(false);
    const [typedName, setTypedName] = useState('');
    const collected: { options: SelectOption[]; placeholder?: string } = { options: [] };
    collect(children, collected);

    const offersLedger = newLedger !== undefined && canManageChart && !disabled;
    const offersEntity = quickCreate !== undefined && !disabled;
    const offersCounterparty = newCounterparty !== undefined && canManageCounterparties && !disabled;
    const offersCreate = offersLedger || offersEntity || offersCounterparty;
    const createLabel = offersLedger
        ? 'New ledger head'
        : offersCounterparty
          ? newCounterparty.label
          : (quickCreate?.createLabel ?? `New ${quickCreate?.kind ?? ''}`);

    return (
        <>
            <SearchableSelect
                id={id}
                name={name}
                value={value === undefined || value === null ? '' : String(value)}
                onChange={(next) => onChange({ target: { value: next, name: name ?? '', id: id ?? '' } })}
                options={collected.options}
                placeholder={collected.placeholder && collected.placeholder !== '' ? collected.placeholder : 'Select…'}
                searchPlaceholder={searchPlaceholder}
                emptyText={emptyText}
                className={className}
                disabled={disabled}
                invalid={invalid}
                searchThreshold={searchThreshold}
                fit={fit}
                required={required}
                onCreate={
                    offersCreate
                        ? (typed) => {
                              setTypedName(typed);
                              setCreating(true);
                          }
                        : undefined
                }
                createLabel={createLabel}
            />
            {offersLedger ? (
                <LedgerQuickCreate
                    open={creating}
                    onOpenChange={setCreating}
                    types={newLedger.types}
                    initialName={typedName}
                    onCreated={(account) => {
                        // The list comes from a page prop: fetch just that prop, then
                        // choose the new head once it is really in the list.
                        router.reload({
                            only: newLedger.reload,
                            onSuccess: () => onChange({ target: { value: String(account.id), name: name ?? '', id: id ?? '' } }),
                        });
                    }}
                />
            ) : null}
            {offersCounterparty ? (
                <CounterpartyDialog
                    quick
                    open={creating}
                    onOpenChange={setCreating}
                    initial={{ ...blankCounterparty(typedName), related_party: newCounterparty.related === true }}
                    onSaved={(row) => {
                        router.reload({
                            only: newCounterparty.reload,
                            onSuccess: () => onChange({ target: { value: String(row.id), name: name ?? '', id: id ?? '' } }),
                        });
                    }}
                />
            ) : null}
            {offersEntity ? (
                <EntityQuickCreate
                    kind={quickCreate.kind}
                    open={creating}
                    onOpenChange={setCreating}
                    initialName={typedName}
                    onCreated={(row) => {
                        router.reload({
                            only: quickCreate.reload,
                            onSuccess: () => onChange({ target: { value: String(row.id), name: name ?? '', id: id ?? '' } }),
                        });
                    }}
                />
            ) : null}
        </>
    );
}
