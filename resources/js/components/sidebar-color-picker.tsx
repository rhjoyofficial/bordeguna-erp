import { sidebarColors, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import { HTMLAttributes } from 'react';

export default function SidebarColorPicker({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { sidebarColor, updateSidebarColor } = useAppearance();

    return (
        <div className={cn('flex flex-wrap gap-3', className)} {...props}>
            {sidebarColors.map(({ value, label, swatch }) => (
                <button
                    key={value}
                    type="button"
                    onClick={() => updateSidebarColor(value)}
                    aria-pressed={sidebarColor === value}
                    className={cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                        sidebarColor === value
                            ? 'border-ring ring-ring/40 ring-2'
                            : 'border-border hover:bg-accent text-muted-foreground hover:text-foreground',
                    )}
                >
                    <span
                        className="flex h-5 w-5 items-center justify-center rounded-full border border-black/10"
                        style={{ backgroundColor: swatch }}
                    >
                        {sidebarColor === value && <Check className={cn('h-3.5 w-3.5', value === 'light' ? 'text-black' : 'text-white')} />}
                    </span>
                    {label}
                </button>
            ))}
        </div>
    );
}
