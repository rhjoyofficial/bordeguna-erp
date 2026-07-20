import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type FilterValues = Record<string, string>;

// Keeps a set of list filters in local state and pushes them to the server as
// query params (debounced) via a partial Inertia visit. Empty values are
// dropped so the URL stays clean, and every filter change resets to page 1.
export function useFilters<T extends FilterValues>(baseUrl: string, initial: T) {
    const [filters, setFilters] = useState<T>(initial);
    const isFirstRun = useRef(true);

    useEffect(() => {
        if (isFirstRun.current) {
            isFirstRun.current = false;
            return;
        }

        const handler = setTimeout(() => {
            const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));

            router.get(baseUrl, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);

        return () => clearTimeout(handler);
    }, [filters, baseUrl]);

    const setFilter = (key: keyof T, value: string) => {
        setFilters((current) => ({ ...current, [key]: value }));
    };

    const reset = () => {
        setFilters((current) => {
            const cleared = { ...current };
            (Object.keys(cleared) as (keyof T)[]).forEach((key) => {
                cleared[key] = '' as T[keyof T];
            });
            return cleared;
        });
    };

    const hasActiveFilters = Object.values(filters).some((value) => value !== '' && value !== null);

    return { filters, setFilter, reset, hasActiveFilters };
}
