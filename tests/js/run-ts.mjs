/* global process */
/**
 * Runs a TypeScript test harness from tests/js against the real modules of
 * resources/js: esbuild (already installed with Vite) bundles it with the
 * project's tsconfig paths, so `@/lib/sections` is the file the browser gets,
 * and node runs the bundle. The harness reads its calls as JSON on stdin and
 * prints JSON results; the Pest test asserts them.
 *
 *   node tests/js/run-ts.mjs tests/js/section-navigation.harness.ts < calls.json
 */
import { build } from 'esbuild';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = resolve(import.meta.dirname, '../..');
const entry = resolve(root, process.argv[2]);

const result = await build({
    entryPoints: [entry],
    absWorkingDir: root,
    tsconfig: join(root, 'tsconfig.json'),
    bundle: true,
    platform: 'node',
    format: 'esm',
    jsx: 'automatic',
    write: false,
    logLevel: 'error',
});

const dir = mkdtempSync(join(tmpdir(), 'erp-ts-harness-'));
const file = join(dir, 'harness.mjs');
writeFileSync(file, result.outputFiles[0].text);

try {
    await import(pathToFileURL(file).href);
} finally {
    rmSync(dir, { recursive: true, force: true });
}
