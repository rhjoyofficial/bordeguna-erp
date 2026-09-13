import { cn } from '@/lib/utils';

/**
 * The settings page frame.
 *
 * Joy, 30 August 2026: the settings links move into the app sidebar, and the
 * pages use the whole screen.
 *
 * ⚠⚠ WHAT WAS REMOVED AND WHY. This layout used to draw a SECOND navigation
 * column inside the page, beside the app sidebar that was already there, and
 * then clamp what was left to 576px. So the audit trail and the messaging
 * gateways - wide tables, both - were squeezed into a third of the window while
 * two thirds held a nav and white space, and a page had to opt out with a
 * `wide` flag to be readable at all. One nav, in the sidebar where every other
 * link lives, and the content gets the window.
 *
 * ⚠ `narrow` is for the short personal forms - profile, password, appearance.
 * A text input stretched across a wide monitor is not more usable, it is harder
 * to read; those three keep a sane measure. Everything else fills the screen.
 *
 * ⚠ `wide` is still accepted and ignored, so no page breaks on the way through.
 * It has no meaning now: wide is what every settings page is.
 */
export default function SettingsLayout({
    children,
    narrow = false,
}: {
    children: React.ReactNode;
    narrow?: boolean;
    /** @deprecated every settings page is full width now. */
    wide?: boolean;
}) {
    return (
        <div className="flex w-full flex-col gap-6 p-4">
            <section className={cn('w-full min-w-0 space-y-8', narrow && 'max-w-2xl')}>{children}</section>
        </div>
    );
}
