import { type NavGroup } from '@/components/nav-groups';

export type MenuMatch = { label: string; sub: string; url: string };

/**
 * The header search's "Menus" matching, kept free of React so it can be
 * tested on its own. Every word typed must appear in the page name, its group
 * label or its keywords (old link names, what people call the screen). The
 * first group that offers a URL keeps it: the drawn sidebar groups come
 * first, then the never-drawn tab and Settings groups (useNavGroups).
 */
export function matchMenus(groups: NavGroup[], q: string): MenuMatch[] {
    const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length === 0 || q.trim().length < 2) {
        return [];
    }

    const seen = new Set<string>();
    const found: MenuMatch[] = [];
    groups.forEach((group) => {
        group.items.forEach((item) => {
            const haystack = `${item.title} ${group.label} ${item.keywords ?? ''}`.toLowerCase();
            if (!seen.has(item.url) && words.every((word) => haystack.includes(word))) {
                seen.add(item.url);
                found.push({ label: item.title, sub: group.label, url: item.url });
            }
        });
    });

    return found;
}
