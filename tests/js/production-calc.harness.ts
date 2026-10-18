/**
 * PR3 (18 Oct 2026): the production run form's fill arithmetic, RUN rather
 * than read. Read by tests/Unit/ProductionCalcUiTest.php through
 * tests/js/run-ts.mjs.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { type CostLayer, type RecipeLineSpec, fifoEstimate, fromStock, outputFromComponent, suggestQuantity, toStock } from '@/lib/recipe-fill';
import { readFileSync } from 'node:fs';

type Call =
    | { op: 'suggest'; line: RecipeLineSpec; recipeOutput: string; output: number; whole: boolean }
    | { op: 'output'; line: RecipeLineSpec; recipeOutput: string; component: number; whole: boolean }
    | { op: 'toStock'; quantity: string; factor: string }
    | { op: 'fromStock'; quantity: number; factor: string }
    | { op: 'fifo'; layers: CostLayer[]; quantity: number };

function run(call: Call): unknown {
    switch (call.op) {
        case 'suggest':
            return suggestQuantity(call.line, call.recipeOutput, call.output, call.whole);
        case 'output':
            return outputFromComponent(call.line, call.recipeOutput, call.component, call.whole);
        case 'toStock':
            return toStock(call.quantity, call.factor);
        case 'fromStock':
            return fromStock(call.quantity, call.factor);
        case 'fifo':
            return fifoEstimate(call.layers, call.quantity);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
