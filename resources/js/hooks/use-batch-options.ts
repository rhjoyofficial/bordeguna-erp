import { useEffect, useState } from 'react';

/** One batch of a product in a warehouse, as StockController::batchOptions lists it. */
export interface BatchOption {
    id: number;
    batch_number: string;
    expiry_date: string | null;
    received_on: string | null;
    current_quantity: string;
    unit_cost: string;
    expired: boolean;
    on_hold: boolean;
    /** ⭐ B8: the supplier the batch was bought from, when it came on a goods receipt. */
    supplier?: string | null;
}

/**
 * ⭐ Initial plan B7 and B8 (24 Oct 2026): the batches of one product in one
 * warehouse, for Move back to stock and the production run's batch picker.
 * A GET to the same endpoint the stock correction popup reads (a hand-written
 * fetch, listed in HandWrittenFetchTest), so no token to send. Null = not
 * asked yet (no product or warehouse, or switched off).
 */
export function useBatchOptions(productId: string, warehouseId: string, enabled: boolean = true) {
    const [batches, setBatches] = useState<BatchOption[] | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!enabled || !productId || !warehouseId) {
            setBatches(null);
            return;
        }

        let cancelled = false;
        setLoading(true);
        const query = new URLSearchParams({ product_id: productId, warehouse_id: warehouseId });
        fetch(`${route('inventory.stock.batch-options')}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : { batches: [] }))
            .then((body: { batches: BatchOption[] }) => {
                if (!cancelled) setBatches(body.batches);
            })
            .catch(() => {
                if (!cancelled) setBatches([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [productId, warehouseId, enabled]);

    return { batches, loading };
}
