import AccentPicker from '@/components/accent-picker';
import AppearanceTabs from '@/components/appearance-tabs';
import SidebarColorPicker from '@/components/sidebar-color-picker';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import TopbarColorPicker from '@/components/topbar-color-picker';
import { resetThemePreferences } from '@/hooks/use-appearance';
import { Settings2 } from 'lucide-react';
import { useState } from 'react';

/**
 * The floating gear panel from the Dreams POS reference — every page under
 * AppSidebarLayout gets one. Deliberately smaller than the reference: no
 * layout-variant thumbnails (Boxed/RTL/Horizontal/Detached — template-vendor
 * breadth this ERP doesn't need), just the three independent color pickers
 * plus light/dark/system, matching Phase 1 of the plan.
 */
export default function ThemeCustomizer() {
    const [open, setOpen] = useState(false);
    // Bumped on reset to remount the pickers below — each owns its own
    // useAppearance() instance and local state, so a reset that only touches
    // localStorage/the DOM (see resetThemePreferences) wouldn't otherwise be
    // reflected in their "active" swatch highlighting until next navigation.
    const [resetKey, setResetKey] = useState(0);

    const handleReset = () => {
        resetThemePreferences();
        setResetKey((count) => count + 1);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Open theme customizer"
                className="border-border bg-primary text-primary-foreground fixed right-4 bottom-4 z-40 flex h-11 w-11 items-center justify-center rounded-full border shadow-lg transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Settings2 className="h-5 w-5" />
            </button>

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-sm">
                    <SheetHeader>
                        <SheetTitle>Theme customizer</SheetTitle>
                        <SheetDescription>Personal to your account — saved to this browser.</SheetDescription>
                    </SheetHeader>

                    <div key={resetKey} className="mt-6 space-y-6 px-4 pb-6">
                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Mode</h3>
                            <AppearanceTabs />
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Accent color</h3>
                            <p className="text-muted-foreground text-xs">Buttons, links and the active menu item.</p>
                            <AccentPicker />
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Sidebar color</h3>
                            <SidebarColorPicker />
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Topbar color</h3>
                            <TopbarColorPicker />
                        </section>

                        <Button variant="outline" className="w-full" onClick={handleReset}>
                            Reset to defaults
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
