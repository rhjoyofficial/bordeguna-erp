/**
 * The shared UI rules of the 17 Oct 2026 practice run, RUN rather than read:
 * when a popup asks "Discard what you typed?" (F11/F26), and how numbers and
 * dates are written (F13/F18/F19/F22/F27). Read by
 * tests/Unit/PracticeUiTest.php through tests/js/run-ts.mjs.
 *
 * ⚠ There is no DOM in node, so an event target is a small stand-in element
 * with a real (if tiny) selector matcher: tag, [attr], [attr="value"] and
 * :not(...), comma lists. That is every form the dialog's selectors use, and
 * the selectors themselves are the real exported strings.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { closeNeedsConfirm, marksEdited, type ClosestTarget } from '@/components/ui/dialog';
import { formatDate } from '@/lib/dates';
import { codeLabel, formatMoney, formatQuantity, plainNumber } from '@/lib/utils';
import { readFileSync } from 'node:fs';

interface FakeElement {
    tag: string;
    attrs?: Record<string, string>;
    parent?: FakeElement;
}

/** One compound selector (no combinators) against one element. */
function matchesCompound(element: FakeElement, compound: string): boolean {
    let rest = compound.trim();
    const tag = /^[a-z][a-z0-9-]*/i.exec(rest);
    if (tag) {
        if (element.tag.toLowerCase() !== tag[0].toLowerCase()) return false;
        rest = rest.slice(tag[0].length);
    }

    while (rest.length > 0) {
        if (rest.startsWith(':not(')) {
            let depth = 0;
            let end = 0;
            for (let i = 4; i < rest.length; i++) {
                if (rest[i] === '(') depth++;
                if (rest[i] === ')') depth--;
                if (depth === 0) {
                    end = i;
                    break;
                }
            }
            if (matchesCompound(element, rest.slice(5, end))) return false;
            rest = rest.slice(end + 1);
            continue;
        }

        const attr = /^\[([a-z-]+)(?:="([^"]*)")?\]/i.exec(rest);
        if (!attr) throw new Error(`Selector the stand-in cannot read: ${compound}`);
        const value = element.attrs?.[attr[1]];
        if (value === undefined) return false;
        if (attr[2] !== undefined && value !== attr[2]) return false;
        rest = rest.slice(attr[0].length);
    }

    return true;
}

function splitList(selector: string): string[] {
    const parts: string[] = [];
    let depth = 0;
    let start = 0;
    for (let i = 0; i < selector.length; i++) {
        if (selector[i] === '(') depth++;
        if (selector[i] === ')') depth--;
        if (selector[i] === ',' && depth === 0) {
            parts.push(selector.slice(start, i));
            start = i + 1;
        }
    }
    parts.push(selector.slice(start));

    return parts;
}

function target(element: FakeElement | null): ClosestTarget | null {
    if (element === null) return null;

    return {
        closest(selector: string) {
            for (let at: FakeElement | undefined = element; at; at = at.parent) {
                if (splitList(selector).some((part) => matchesCompound(at!, part))) return at;
            }

            return null;
        },
    };
}

type Call =
    | { op: 'edited'; kind: 'change' | 'pick' | 'enter'; target: FakeElement | null }
    | { op: 'confirm'; dirty?: boolean; edited: boolean; confirmDiscard?: boolean }
    | { op: 'quantity'; value: string | number | null }
    | { op: 'money'; value: string | number | null }
    | { op: 'plain'; value: string | number | null }
    | { op: 'label'; code: string | null; labels?: { value: string; label: string }[] }
    | { op: 'date'; value: string | null };

function run(call: Call): unknown {
    switch (call.op) {
        case 'edited':
            return marksEdited(call.kind, target(call.target));
        case 'confirm':
            return closeNeedsConfirm({ dirty: call.dirty, edited: call.edited, confirmDiscard: call.confirmDiscard });
        case 'quantity':
            return formatQuantity(call.value);
        case 'money':
            return formatMoney(call.value);
        case 'plain':
            return plainNumber(call.value);
        case 'label':
            return codeLabel(call.code, call.labels);
        case 'date':
            return formatDate(call.value);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
