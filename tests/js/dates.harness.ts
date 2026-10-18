/**
 * DD/MM/YYYY and Dhaka time (Joy, 18 Oct 2026), RUN rather than read: the
 * date helpers every screen and every date box use. The Pest test starts node
 * with TZ=America/New_York, so a helper that leaned on the PC's own clock or
 * locale would fail here. Read by tests/Unit/DatesDmyUiTest.php.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { addDays, completeDate, datesInText, dhakaClock, dmy, maskDate, maskMonth, parseDmy, parseMy, showDate, showInstant } from '@/lib/dates';
import { readFileSync } from 'node:fs';

type Call =
    | { op: 'parse'; text: string }
    | { op: 'parseMonth'; text: string }
    | { op: 'show'; value: string | null }
    | { op: 'instant'; ms: number }
    | { op: 'clock'; ms: number }
    | { op: 'dmy'; value: string }
    | { op: 'addDays'; iso: string; days: number }
    | { op: 'type'; keys: string[] }
    | { op: 'typeMonth'; keys: string[] }
    | { op: 'complete'; text: string }
    | { op: 'text'; text: string };

/** Types one chunk at a time, as the box would see it. "<BS>" is Backspace. */
function typed(keys: string[], mask: (raw: string, previous: string) => string): string {
    let text = '';
    for (const key of keys) text = mask(key === '<BS>' ? text.slice(0, -1) : text + key, text);

    return text;
}

function run(call: Call): unknown {
    switch (call.op) {
        case 'parse':
            return parseDmy(call.text);
        case 'parseMonth':
            return parseMy(call.text);
        case 'show':
            return showDate(call.value);
        case 'instant':
            return showInstant(call.ms);
        case 'clock':
            return dhakaClock(call.ms);
        case 'dmy':
            return dmy(call.value);
        case 'addDays':
            return addDays(call.iso, call.days);
        case 'type':
            return typed(call.keys, maskDate);
        case 'typeMonth':
            return typed(call.keys, maskMonth);
        case 'complete':
            return completeDate(call.text);
        case 'text':
            return datesInText(call.text);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
