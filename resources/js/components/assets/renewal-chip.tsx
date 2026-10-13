import { Badge } from '@/components/ui/badge';

/**
 * A renewal date as a chip (accounts hub, slice 4): amber within 30 days,
 * red once overdue, plain otherwise. Colour carries the meaning; the date is
 * always there.
 */
export function RenewalChip({ renews_on, tone }: { renews_on: string | null; tone: 'overdue' | 'soon' | null }) {
    if (!renews_on) {
        return <span className="text-muted-foreground">-</span>;
    }

    if (tone === 'overdue') {
        return <Badge variant="destructive">Overdue {renews_on}</Badge>;
    }

    if (tone === 'soon') {
        return (
            <Badge className="border-amber-500/50 bg-amber-500/10 text-amber-700 dark:text-amber-400" variant="outline">
                Renews {renews_on}
            </Badge>
        );
    }

    return <span>{renews_on}</span>;
}
