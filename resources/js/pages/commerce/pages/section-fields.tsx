import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, Trash2 } from 'lucide-react';

/**
 * One form, generated from the section contract, for all twenty section types.
 *
 * ⭐⭐ THIS IS WHY THE CONTRACT WAS WORTH BUILDING. Twenty hand-written forms
 * would drift from each other and from the validator, which is exactly how the
 * shops ended up with a create screen and an edit screen that disagreed about
 * three fields. Here the ERP declares the fields once, the server validates
 * against that declaration, and this file draws whatever it is given.
 *
 * ⛔ There is no `price`, `stock`, `availability` or `delivery` control in this
 * file, and there never will be, because no such field type exists. A section
 * must not store business truth: all of it is asked of the ERP at render time.
 */

export interface FieldSpec {
    name: string;
    type: string;
    required?: boolean;
    min?: number;
    max?: number;
    options?: string[];
    of?: FieldSpec[];
    help?: string;
}

export interface Reference {
    id: number;
    label: string;
}

export interface MediaItem {
    id: number;
    url: string;
    alt: string;
    alt_decided: boolean;
    width: number | null;
    height: number | null;
    original_name: string;
}

type Payload = Record<string, unknown>;

/** `story_title` reads as a column name; "Story title" reads as a question. */
function humanise(name: string): string {
    const words = name.replace(/_/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function referenceOptions(type: string, products: Reference[], categories: Reference[]): Reference[] | null {
    if (type === 'product_ref') return products;
    if (type === 'category_ref') return categories;

    return null;
}

export function SectionFields({
    fields,
    payload,
    onChange,
    products,
    categories,
    media,
    errors = {},
    path = '',
}: {
    fields: FieldSpec[];
    payload: Payload;
    onChange: (payload: Payload) => void;
    products: Reference[];
    categories: Reference[];
    media: MediaItem[];
    errors?: Record<string, string>;
    path?: string;
}) {
    const set = (name: string, value: unknown) => onChange({ ...payload, [name]: value });

    return (
        <div className="grid gap-4">
            {fields.map((field) => {
                const key = path ? `${path}.${field.name}` : field.name;
                const value = payload[field.name];

                return (
                    <div key={key} className="grid gap-2">
                        {/* Required to PUBLISH: a draft save tolerates it missing
                            (SectionPayloadValidator), so no HTML `required`. */}
                        <Label htmlFor={key} required={field.required}>
                            {humanise(field.name)}
                        </Label>

                        {field.help && <p className="text-muted-foreground -mt-1 text-xs">{field.help}</p>}

                        <FieldControl
                            id={key}
                            field={field}
                            value={value}
                            onChange={(next) => set(field.name, next)}
                            products={products}
                            categories={categories}
                            media={media}
                            errors={errors}
                            path={key}
                        />

                        <InputError message={errors[key]} />
                    </div>
                );
            })}
        </div>
    );
}

function FieldControl({
    id,
    field,
    value,
    onChange,
    products,
    categories,
    media,
    errors,
    path,
}: {
    id: string;
    field: FieldSpec;
    value: unknown;
    onChange: (value: unknown) => void;
    products: Reference[];
    categories: Reference[];
    media: MediaItem[];
    errors: Record<string, string>;
    path: string;
}) {
    const references = referenceOptions(field.type, products, categories);

    // ⚠ A reference stores an ID and never a copy. A section that copied a
    // product's title would still be showing the old one long after somebody
    // renamed the product.
    if (references) {
        return (
            <Select
                value={value === undefined || value === null ? '' : String(value)}
                onValueChange={(next) => onChange(next === '' ? null : Number(next))}
            >
                <SelectTrigger id={id} aria-required={field.required}>
                    <SelectValue placeholder={references.length ? 'Choose one' : 'Nothing to choose from yet'} />
                </SelectTrigger>
                <SelectContent>
                    {references.map((reference) => (
                        <SelectItem key={reference.id} value={String(reference.id)}>
                            {reference.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    switch (field.type) {
        case 'media':
            return <MediaPicker id={id} value={typeof value === 'number' ? value : null} onChange={onChange} media={media} />;

        case 'richtext':
            return (
                <textarea
                    id={id}
                    aria-required={field.required}
                    className="border-input bg-background min-h-32 w-full rounded-md border px-3 py-2 text-sm"
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'text':
            // ⚠ A long text field gets a box the size of what it holds. A 200
            // character description in a single-line input is how copy gets
            // written short enough to fit the control rather than the page.
            return (field.max ?? 0) > 120 ? (
                <textarea
                    id={id}
                    aria-required={field.required}
                    maxLength={field.max}
                    className="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : (
                <Input
                    id={id}
                    aria-required={field.required}
                    maxLength={field.max}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'link':
            return (
                <Input
                    id={id}
                    aria-required={field.required}
                    placeholder="/products or https://example.com"
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'enum':
            return (
                <Select value={typeof value === 'string' ? value : ''} onValueChange={(next) => onChange(next === '' ? null : next)}>
                    <SelectTrigger id={id} aria-required={field.required}>
                        <SelectValue placeholder="Choose one" />
                    </SelectTrigger>
                    <SelectContent>
                        {(field.options ?? []).map((option) => (
                            <SelectItem key={option} value={option}>
                                {humanise(option)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            );

        case 'bool':
            return (
                <div className="flex items-center gap-2">
                    <Checkbox id={id} checked={value === true} onCheckedChange={(next) => onChange(next === true)} />
                    <Label htmlFor={id} className="text-muted-foreground text-sm font-normal">
                        Yes
                    </Label>
                </div>
            );

        case 'int':
        case 'decimal':
            return (
                <Input
                    id={id}
                    aria-required={field.required}
                    type="number"
                    min={field.min}
                    max={field.max}
                    value={typeof value === 'number' ? String(value) : ''}
                    // ⚠ Sent as a NUMBER, not a string. The contract's own
                    // validator asks `is_int`, and a numeric string would be
                    // refused with a message about the wrong thing.
                    onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
                />
            );

        case 'list':
            return (
                <ListField
                    field={field}
                    value={Array.isArray(value) ? (value as Payload[]) : []}
                    onChange={onChange}
                    products={products}
                    categories={categories}
                    media={media}
                    errors={errors}
                    path={path}
                />
            );

        default:
            return <div className="text-muted-foreground text-sm">This ERP has no control for a [{field.type}] field yet.</div>;
    }
}

/**
 * Choose an image from this store's library.
 *
 * ⛔ The list offered is the STORE's, never the business's, so a section on one
 * shop cannot reach for another shop's photography. That is the same class of
 * defect as a fork inheriting the original's pixel id, and the cheapest place
 * to stop it is by never offering the wrong thing.
 *
 * ⚠ An image whose alt text nobody has decided is shown and selectable, and
 * labelled. It is a legitimate half-finished state; it is simply not a
 * publishable one, and the publish gate says so with the file's name.
 */
function MediaPicker({ id, value, onChange, media }: { id: string; value: number | null; onChange: (value: unknown) => void; media: MediaItem[] }) {
    const chosen = media.find((item) => item.id === value) ?? null;

    if (media.length === 0) {
        return (
            <div className="border-input text-muted-foreground rounded-md border border-dashed px-3 py-2 text-sm">
                This store has no images yet. Add some in Websites &rarr; Media, and they will appear here.
            </div>
        );
    }

    return (
        <div className="grid gap-3">
            {chosen && (
                <div className="flex items-center gap-3 rounded-md border p-2">
                    <img src={chosen.url} alt={chosen.alt} className="h-16 w-16 rounded object-cover" />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="truncate font-medium">{chosen.original_name}</p>
                        <p className="text-muted-foreground text-xs">
                            {chosen.width && chosen.height ? `${chosen.width} x ${chosen.height}` : 'size unknown'}
                            {!chosen.alt_decided && <span className="text-destructive"> &middot; alt text not decided</span>}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>
                        Clear
                    </Button>
                </div>
            )}

            <Select value={value === null ? '' : String(value)} onValueChange={(next) => onChange(next === '' ? null : Number(next))}>
                <SelectTrigger id={id}>
                    <SelectValue placeholder="Choose an image" />
                </SelectTrigger>
                <SelectContent>
                    {media.map((item) => (
                        <SelectItem key={item.id} value={String(item.id)}>
                            {item.original_name}
                            {!item.alt_decided ? ' (no alt text)' : ''}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/**
 * A repeatable group: slides, steps, questions and answers.
 *
 * ⚠ The cardinality the contract declares is shown and enforced in the UI as
 * well as on the server, so an author is told they need at least two slides
 * while they are looking at one, not after pressing save.
 */
function ListField({
    field,
    value,
    onChange,
    products,
    categories,
    media,
    errors,
    path,
}: {
    field: FieldSpec;
    value: Payload[];
    onChange: (value: Payload[]) => void;
    products: Reference[];
    categories: Reference[];
    media: MediaItem[];
    errors: Record<string, string>;
    path: string;
}) {
    const shape = field.of ?? [];
    const max = field.max ?? 50;
    const min = field.min ?? 0;

    const replace = (index: number, entry: Payload) => onChange(value.map((old, i) => (i === index ? entry : old)));

    const move = (index: number, by: number) => {
        const target = index + by;
        if (target < 0 || target >= value.length) return;

        const next = [...value];
        [next[index], next[target]] = [next[target], next[index]];
        onChange(next);
    };

    return (
        <div className="grid gap-3">
            {value.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Nothing here yet{min > 0 ? `, and at least ${min} ${min === 1 ? 'entry is' : 'entries are'} needed` : ''}.
                </p>
            )}

            {value.map((entry, index) => (
                <div key={index} className="bg-muted/30 rounded-md border p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-muted-foreground text-xs font-medium">#{index + 1}</span>
                        <div className="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" disabled={index === 0} onClick={() => move(index, -1)}>
                                Up
                            </Button>
                            <Button type="button" variant="ghost" size="sm" disabled={index === value.length - 1} onClick={() => move(index, 1)}>
                                Down
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => onChange(value.filter((_, i) => i !== index))}>
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </div>

                    <SectionFields
                        fields={shape}
                        payload={entry}
                        onChange={(next) => replace(index, next)}
                        products={products}
                        categories={categories}
                        media={media}
                        errors={errors}
                        path={`${path}.${index}`}
                    />
                </div>
            ))}

            <div>
                <Button type="button" variant="outline" size="sm" disabled={value.length >= max} onClick={() => onChange([...value, {}])}>
                    <Plus className="mr-1 size-4" />
                    Add {value.length >= max ? `(at most ${max})` : ''}
                </Button>
            </div>
        </div>
    );
}
