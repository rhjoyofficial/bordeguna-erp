/**
 * Market pricing screen rules (18 Oct 2026, Joy D-E; practice P16), RUN rather
 * than read: which pricing parts of the product form show per mode, and where
 * a typed price stands against the batch cost. Read by
 * tests/Unit/MarketPricingUiTest.php through tests/js/run-ts.mjs.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { priceStanding, pricingSectionsFor, type PricingModeValue } from '@/lib/market-pricing';
import { readFileSync } from 'node:fs';

type Call = { op: 'sections'; mode: PricingModeValue | null } | { op: 'standing'; price: string; cost: string | null };

function run(call: Call): unknown {
    switch (call.op) {
        case 'sections':
            return pricingSectionsFor(call.mode);
        case 'standing':
            return priceStanding(call.price, call.cost);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
