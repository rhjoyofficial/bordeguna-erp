/** One batch a challan line took (DeliveredBatches on the server). */
export interface LineBatch {
    batch_number: string;
    /** Already in words ("12 Mar 2027"), or null when the batch has none. */
    expiry_date: string | null;
    quantity: string;
}

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The batch number and expiry under an item on a printed challan or invoice
 * (owner, 17 Oct 2026: a recall starts from the paper the customer holds).
 * The quantity per batch is shown only when one line took more than one.
 */
export function LineBatches({ batches }: { batches: LineBatch[] | undefined }) {
    if (!batches || batches.length === 0) {
        return null;
    }

    return (
        <>
            {batches.map((batch) => (
                <p key={batch.batch_number} className="text-xs text-black/60">
                    Batch {batch.batch_number}
                    {batch.expiry_date && <> · EXP {batch.expiry_date}</>}
                    {batches.length > 1 && <> · {quantity(batch.quantity)}</>}
                </p>
            ))}
        </>
    );
}
