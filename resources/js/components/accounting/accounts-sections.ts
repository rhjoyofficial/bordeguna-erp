import { type ModuleSection } from '@/lib/sections';

/**
 * Accounts in ONE sidebar group (redesign round 3, 16 Oct 2026). Empty until
 * the Accounts build fills it: the registry (lib/sections) and the header
 * search already read this list, after Inventory, Purchases and Sales, so a
 * shared report address keeps their tab row for whoever may open it.
 */
export const ACCOUNTS_SECTIONS: ModuleSection[] = [];
