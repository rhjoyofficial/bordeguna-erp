import type { Entry } from './entry';

/**
 * Customer service tickets (CRM-011, 14 Oct 2026): handing an open ticket to
 * somebody else from its card. A ticket's own subject and replies are data
 * somebody typed and never come through here.
 */
export const tickets = {
    'tickets.reassign.label': { en: 'Hand to', bn: 'কাকে দেবেন' },
    'tickets.reassign.placeholder': { en: 'Choose a person', bn: 'একজন বেছে নিন' },
    'tickets.reassign.save': { en: 'Reassign', bn: 'দায়িত্ব বদলান' },
} satisfies Record<string, Entry>;
