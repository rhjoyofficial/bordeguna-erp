import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { Children, isValidElement, type ReactNode } from 'react';

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

        into.options.push({ value, label, disabled: props.disabled });
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
}) {
    const collected: { options: SelectOption[]; placeholder?: string } = { options: [] };
    collect(children, collected);

    return (
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
        />
    );
}
