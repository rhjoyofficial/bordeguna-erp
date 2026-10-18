/**
 * The 18 Oct 2026 practice run's UI rules, RUN rather than read: the history
 * guard behind P8, the exact-match picker (P4), the number boxes (P11), the
 * recipe fill (P9) and the brand filter on a sales order. Read by
 * tests/Unit/PracticeEighteenUiTest.php through tests/js/run-ts.mjs.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { pickerScore } from '@/components/searchable-select';
import { lineBrandMismatch, productsForBrand } from '@/lib/brand-products';
import { seedHistoryState } from '@/lib/history-state-guard';
import { recipeStep } from '@/lib/recipe-fill';
import { decimalInput, formatMoney, plainNumber } from '@/lib/utils';
import { readFileSync } from 'node:fs';

type Call =
    | { op: 'seed'; state: unknown; page: unknown }
    | { op: 'score'; row: string; label: string; search: string }
    | { op: 'decimal'; value: string }
    | { op: 'recipe'; hasRecipe: boolean; quantity: string; touched: boolean }
    | { op: 'brandFilter'; products: { id: number; brand_id: number | null }[]; brandId: string; keep?: string }
    | { op: 'mismatch'; product: { id: number; brand_id: number | null } | null; brandId: string }
    | { op: 'plain'; value: string }
    | { op: 'money'; value: number };

function run(call: Call): unknown {
    switch (call.op) {
        case 'seed': {
            const writes: unknown[] = [];
            const history = {
                state: call.state,
                replaceState(data: unknown) {
                    writes.push(data);
                },
            };
            const seeded = seedHistoryState(history, call.page, 'http://erp.test/x');

            return { seeded, writes };
        }
        case 'score':
            return pickerScore(call.row, call.label, call.search);
        case 'decimal':
            return decimalInput(call.value);
        case 'recipe':
            return recipeStep({ hasRecipe: call.hasRecipe, quantity: call.quantity, touched: call.touched });
        case 'brandFilter':
            return productsForBrand(call.products, call.brandId, call.keep ?? '').map((product) => product.id);
        case 'mismatch':
            return lineBrandMismatch(call.product ?? undefined, call.brandId);
        case 'plain':
            return plainNumber(call.value);
        case 'money':
            return formatMoney(call.value);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
