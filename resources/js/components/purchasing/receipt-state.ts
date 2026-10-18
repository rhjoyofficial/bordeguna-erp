import { type TranslationKey } from '@/lib/i18n';

/**
 * ⭐ PR2 (18 Oct 2026): a delivery's bill state as the server derives it
 * (ReceiptRegister::billState), with its words and colour, for the Receipts
 * list and the receipt page.
 */
export type BillState = 'open' | 'part' | 'billed';

export const BILL_STATE: Record<BillState, { key: TranslationKey; tone: 'warning' | 'info' | 'ok' }> = {
    open: { key: 'receipts.state_open', tone: 'warning' },
    part: { key: 'receipts.state_part', tone: 'info' },
    billed: { key: 'receipts.state_billed', tone: 'ok' },
};
