import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Ruling P5 (19 Oct 2026): "Bill for a supplier". One supplier's bill may
 * cover several deliveries and orders, or be a service bill with no order at
 * all, so it starts from the supplier, picked in this popup.
 */
export function SupplierBillPicker({ suppliers }: { suppliers: { id: number; name: string }[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [supplierId, setSupplierId] = useState('');

    return (
        <>
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                {t('bill_flex.for_supplier')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent aria-describedby={undefined} confirmDiscard={false}>
                    <DialogHeader>
                        <DialogTitle>{t('bill_flex.for_supplier')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('bill_flex.for_supplier_hint')}</p>
                    <div className="grid gap-1">
                        <Label htmlFor="bill_supplier" required>
                            {t('bill_flex.supplier')}
                        </Label>
                        <SearchSelect fit id="bill_supplier" value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
                            <option value="">Select a supplier</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            {t('bill_flex.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={supplierId === ''}
                            onClick={() => router.visit(`/purchasing/bills/new?supplier=${supplierId}`)}
                        >
                            {t('bill_flex.open')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
