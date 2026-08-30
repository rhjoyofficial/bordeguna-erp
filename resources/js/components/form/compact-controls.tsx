import { createContext, useContext, type ReactNode } from 'react';

/**
 * "The controls in here are as wide as their content, not their column."
 *
 * ⚠⚠ THIS IS ROOT CAUSE A OF THE 30 AUG 2026 AUDIT. SearchableSelect is
 * `w-full` by default, which is right inside a form column and wrong inside a
 * wrapping filter row: a full-width child of `flex-wrap` claims the entire
 * row, so on Customers every dropdown sat alone on its own line and on
 * Invoices the status dropdown stretched across the screen. The `fit` prop
 * that fixes it existed, was used on four pages, and was forgotten on the rest.
 *
 * A filter bar now announces itself through this context and every select
 * inside it fits by itself. No page has to remember, and a page written next
 * month cannot regress.
 *
 * ⚠ An explicit `fit={false}` on a control still wins. The context is a
 * default, not a rule.
 */
const CompactControlsContext = createContext(false);

export function CompactControls({ children }: { children: ReactNode }) {
    return <CompactControlsContext.Provider value={true}>{children}</CompactControlsContext.Provider>;
}

/** Whether the nearest filter bar or toolbar wants content-width controls. */
export function useCompactControls(): boolean {
    return useContext(CompactControlsContext);
}
