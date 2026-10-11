import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/lib/i18n';

// Joy, 11 Oct 2026: every order carries its brand (LaLaDia, Bionic-Garden), and
// challans, parcels and invoices show it through their order.

export interface BrandOption {
    id: number;
    code?: string;
    name: string;
    is_active?: boolean;
}

/** The brand an order is sold under, or a quiet dash when it has none. */
export function BrandBadge({ name, className }: { name: string | null | undefined; className?: string }) {
    if (!name) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <Badge variant="outline" className={className}>
            {name}
        </Badge>
    );
}

/**
 * A list filter: All brands, each brand, and "No brand" for orders taken before
 * brands were recorded. Nothing shows when the business has no brands.
 */
export function BrandFilter({
    id = 'filter-brand',
    value,
    onChange,
    brands,
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    brands: BrandOption[];
}) {
    const { t } = useTranslation();

    if (brands.length === 0) {
        return null;
    }

    return (
        <SearchSelect id={id} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{t('orders.all_brands')}</option>
            {brands.map((b) => (
                <option key={b.id} value={String(b.id)}>
                    {b.name}
                </option>
            ))}
            <option value="none">{t('orders.no_brand')}</option>
        </SearchSelect>
    );
}
