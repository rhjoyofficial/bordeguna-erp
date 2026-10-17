import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Label } from '@/components/ui/label';
import { useEffect, useState } from 'react';

/**
 * District, then thana, then area: three pickers where each list is built from
 * the answer above it, so the three always describe one real place.
 *
 * ⚠ The district list comes in as a prop (64 names, already on the page); the
 * thana and area lists are fetched from `/places/...` when their parent is
 * chosen. NOT `/geo/...`, which is the storefront's path.
 *
 * ⚠ Changing a level CLEARS everything below it. A thana left behind from the
 * previous district would post a pair the server refuses, with the form still
 * showing the old thana as if it were fine.
 *
 * ⚠ Re-check F44, 17 Oct 2026: thana and area always show the search box.
 * Below 8 options the picker drops it, so a small district's thana list
 * opened with nothing to type into, unlike the district picker above it.
 *
 * ⚠ Area is optional everywhere: the dataset's areas are post-office names
 * ("Mirpur TSO"), useful to some and meaningless to most. An empty area list is
 * normal for a few thanas and means the question is simply not asked.
 */
type Place = { district: string; thana: string; area: string };

async function names(url: string): Promise<string[]> {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    return response.ok ? ((await response.json()) as { data: string[] }).data : [];
}

export function BdPlaceFields({
    value,
    onChange,
    districts,
    required = false,
    errors = {},
    idPrefix = 'place',
    withArea = true,
    itemClassName = 'grid gap-2',
}: {
    value: Place;
    onChange: (next: Place) => void;
    districts: string[];
    required?: boolean;
    errors?: Partial<Record<keyof Place, string>>;
    idPrefix?: string;
    /** Off where a thana is the finest grain that means anything, such as a distributor's coverage. */
    withArea?: boolean;
    /**
     * The wrapper of each picker. The fields come back as siblings, so a screen
     * that lays its form out as a wrapping row sizes them here (sales order,
     * 17 Oct 2026: three full-width rows for a district, a thana and an area).
     */
    itemClassName?: string;
}) {
    const [thanas, setThanas] = useState<string[]>([]);
    const [areas, setAreas] = useState<string[]>([]);

    useEffect(() => {
        let live = true;

        if (!value.district) {
            setThanas([]);

            return;
        }

        names(`/places/thanas?district=${encodeURIComponent(value.district)}`).then((list) => live && setThanas(list));

        return () => {
            live = false;
        };
    }, [value.district]);

    useEffect(() => {
        let live = true;

        if (!withArea || !value.district || !value.thana) {
            setAreas([]);

            return;
        }

        names(`/places/areas?district=${encodeURIComponent(value.district)}&thana=${encodeURIComponent(value.thana)}`).then(
            (list) => live && setAreas(list),
        );

        return () => {
            live = false;
        };
    }, [value.district, value.thana, withArea]);

    const asOptions = (list: string[]) => list.map((name) => ({ value: name, label: name }));

    return (
        <>
            <div className={itemClassName}>
                <Label htmlFor={`${idPrefix}_district`} required={required}>
                    District{required ? '' : ' (optional)'}
                </Label>
                <SearchableSelect
                    id={`${idPrefix}_district`}
                    value={value.district}
                    options={asOptions(districts)}
                    placeholder="Choose a district"
                    clearable={!required}
                    invalid={Boolean(errors.district)}
                    onChange={(district) => onChange({ district, thana: '', area: '' })}
                />
                <InputError message={errors.district} />
            </div>
            <div className={itemClassName}>
                <Label htmlFor={`${idPrefix}_thana`} required={required}>
                    Thana{required ? '' : ' (optional)'}
                </Label>
                <SearchableSelect
                    id={`${idPrefix}_thana`}
                    value={value.thana}
                    options={asOptions(thanas)}
                    placeholder={value.district ? 'Choose a thana' : 'Choose the district first'}
                    disabled={!value.district}
                    searchThreshold={0}
                    clearable={!required}
                    invalid={Boolean(errors.thana)}
                    onChange={(thana) => onChange({ ...value, thana, area: '' })}
                />
                <InputError message={errors.thana} />
            </div>
            {withArea && (
                <div className={itemClassName}>
                    <Label htmlFor={`${idPrefix}_area`}>Area (optional)</Label>
                    <SearchableSelect
                        id={`${idPrefix}_area`}
                        value={value.area}
                        options={asOptions(areas)}
                        placeholder={areas.length > 0 ? 'Choose an area' : 'No area to choose'}
                        disabled={areas.length === 0}
                        searchThreshold={0}
                        clearable
                        invalid={Boolean(errors.area)}
                        onChange={(area) => onChange({ ...value, area })}
                    />
                    <InputError message={errors.area} />
                </div>
            )}
        </>
    );
}
