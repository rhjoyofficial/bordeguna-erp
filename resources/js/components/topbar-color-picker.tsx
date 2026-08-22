import { topbarColors, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import { HTMLAttributes } from 'react';

export default function TopbarColorPicker({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { topbarColor, updateTopbarColor } = useAppearance();

    return (
        <div className={cn('flex flex-wrap gap-3', className)} {...props}>
            {topbarColors.map(({ value, label, swatch }) => (
                <button
                    key={value}
                    type="button"
                    onClick={() => updateTopbarColor(value)}
                    aria-pressed={topbarColor === value}
                    className={cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                        topbarColor === value
                            ? 'border-ring ring-ring/40 ring-2'
                            : 'border-border hover:bg-accent text-muted-foreground hover:text-foreground',
                    )}
                >
                    <span
                        className="flex h-5 w-5 items-center justify-center rounded-full border border-black/10"
                        style={{ backgroundColor: swatch }}
                    >
                        {topbarColor === value && <Check className={cn('h-3.5 w-3.5', value === 'light' ? 'text-black' : 'text-white')} />}
                    </span>
                    {label}
                </button>
            ))}
        </div>
    );
}
