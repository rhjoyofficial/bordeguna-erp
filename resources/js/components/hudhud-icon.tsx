import { useId } from 'react';

/**
 * হুদহুদ - the hoopoe, in her real colors: cinnamon head, black-tipped fanned
 * crest, long dark beak. Hand-rolled SVG like every other graphic here.
 *
 * The assistant is named after the hoopoe of Surah An-Naml (27:20-28), so her
 * icon is the bird itself ("colorful and beautiful" - Joy, 2 Sep 2026, after
 * a monochrome first draft). The five crest feathers are drawn once and
 * rotated; the gradient id comes from useId because the icon appears twice on
 * a page (launcher and avatar) and duplicate SVG ids resolve to whichever
 * came first.
 *
 * ⚠ A bird is deliberately the safe imagery here - no person, no calligraphy
 * pretending to be scripture. Keep it that way.
 */
export default function HudhudIcon({ className }: { className?: string }) {
    const gradientId = useId();

    const feather = (angle: number) => (
        <g key={angle} transform={`rotate(${angle} 13.6 10.8)`}>
            <path d="M13.6 10.8 C12.3 8.4 12.7 5.2 13.6 2.8 C14.5 5.2 14.9 8.4 13.6 10.8 Z" fill="#E8853B" />
            <path
                d="M12.95 4.8 C13.15 3.9 13.35 3.2 13.6 2.4 C13.85 3.2 14.05 3.9 14.25 4.8 C14.1 5.6 13.1 5.6 12.95 4.8 Z"
                fill="#242428"
            />
        </g>
    );

    return (
        <svg viewBox="1 1.5 19 17.5" className={className} aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id={gradientId} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#F5A75A" />
                    <stop offset="1" stopColor="#DE7A2A" />
                </linearGradient>
            </defs>
            {[-78, -55, -32, -10, 12].map(feather)}
            <path d="M2.0 13.1 Q6.8 12.1 9.9 12.0 L9.9 13.6 Q6.6 13.9 2.0 13.1 Z" fill="#3A3F46" />
            <circle cx="13.6" cy="13.2" r="4.6" fill={`url(#${gradientId})`} />
            <circle cx="14.9" cy="12.0" r="1.25" fill="#fff" />
            <circle cx="14.9" cy="12.0" r="0.85" fill="#1b1b1f" />
            <circle cx="15.25" cy="11.65" r="0.3" fill="#fff" />
        </svg>
    );
}
