import type { SoundClass, SoundSettings } from '@/components/dashboards/types';

/**
 * Dashboard sounds, synthesised with the browser's own Web Audio (no library,
 * no files to fetch). Three classes (ChatGPT ruling 7):
 *   success    two rising soft notes   (an order, a payment)
 *   attention  two even pulses          (something waits for you)
 *   error      a low falling pair       (a failure)
 *
 * Rules (Owner override, 11 Oct 2026):
 * - never on page load, never for the person's own action (callers decide);
 * - silent until the first click or key press unlocks audio (browsers require it);
 * - at most one sound per 3 seconds; a burst inside that window is bundled into
 *   ONE sound when the window ends, with the most serious class winning;
 * - per-person enable, volume, categories and quiet hours.
 */

const WINDOW_MS = 3000;
const SEVERITY: Record<SoundClass, number> = { success: 1, attention: 2, error: 3 };

type Note = { freq: number; at: number; length: number; type: OscillatorType };

const PATTERNS: Record<SoundClass, Note[]> = {
    success: [
        { freq: 880, at: 0, length: 0.14, type: 'sine' },
        { freq: 1318.5, at: 0.11, length: 0.22, type: 'sine' },
    ],
    attention: [
        { freq: 740, at: 0, length: 0.12, type: 'triangle' },
        { freq: 740, at: 0.2, length: 0.16, type: 'triangle' },
    ],
    error: [
        { freq: 392, at: 0, length: 0.18, type: 'square' },
        { freq: 261.6, at: 0.17, length: 0.3, type: 'square' },
    ],
};

class DashboardSound {
    private context: AudioContext | null = null;
    private unlocked = false;
    private lastPlayed = 0;
    private pending: SoundClass | null = null;
    private timer: number | null = null;
    private recent = new Map<string, number>();
    settings: SoundSettings | null = null;

    constructor() {
        if (typeof window === 'undefined') {
            return;
        }

        const unlock = () => {
            this.unlocked = true;
            this.ensureContext()
                ?.resume()
                .catch(() => {});
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };

        window.addEventListener('pointerdown', unlock);
        window.addEventListener('keydown', unlock);
    }

    private ensureContext(): AudioContext | null {
        if (this.context !== null) {
            return this.context;
        }

        const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

        if (!Ctor) {
            return null;
        }

        this.context = new Ctor();

        return this.context;
    }

    get isUnlocked(): boolean {
        return this.unlocked;
    }

    private inQuietHours(now = new Date()): boolean {
        const quiet = this.settings?.quiet_hours;

        if (!quiet?.enabled) {
            return false;
        }

        const minutes = now.getHours() * 60 + now.getMinutes();
        const parse = (value: string) => {
            const [h, m] = value.split(':').map(Number);

            return h * 60 + m;
        };
        const from = parse(quiet.from);
        const to = parse(quiet.to);

        return from <= to ? minutes >= from && minutes < to : minutes >= from || minutes < to;
    }

    /** Whether this class may sound at all for this person right now. */
    allows(kind: SoundClass): boolean {
        const s = this.settings;

        return Boolean(s?.enabled && s.categories[kind] && s.volume > 0 && !this.inQuietHours());
    }

    /**
     * Ask for a sound. `dedupeKey` drops a repeat of the same happening (the
     * socket and the fallback poll can both report one order).
     */
    notify(kind: SoundClass, dedupeKey?: string): void {
        if (!this.allows(kind) || !this.unlocked) {
            return;
        }

        const now = Date.now();

        if (dedupeKey) {
            const seen = this.recent.get(dedupeKey);

            if (seen !== undefined && now - seen < 15000) {
                return;
            }

            this.recent.set(dedupeKey, now);

            if (this.recent.size > 200) {
                this.recent.clear();
            }
        }

        if (now - this.lastPlayed >= WINDOW_MS && this.timer === null) {
            this.lastPlayed = now;
            this.play(kind);

            return;
        }

        // Inside the window: bundle, keeping the most serious class.
        this.pending = this.pending === null || SEVERITY[kind] > SEVERITY[this.pending] ? kind : this.pending;

        if (this.timer === null) {
            const wait = Math.max(0, WINDOW_MS - (now - this.lastPlayed));
            this.timer = window.setTimeout(() => {
                this.timer = null;
                const bundled = this.pending;
                this.pending = null;

                if (bundled !== null && this.allows(bundled)) {
                    this.lastPlayed = Date.now();
                    this.play(bundled);
                }
            }, wait);
        }
    }

    /** Play immediately, ignoring the rate limit: the settings popover's "Test" button. */
    preview(kind: SoundClass, volume?: number): void {
        this.unlocked = true;
        this.play(kind, volume);
    }

    private play(kind: SoundClass, volumeOverride?: number): void {
        const context = this.ensureContext();

        if (context === null) {
            return;
        }

        context.resume().catch(() => {});

        const volume = Math.min(1, Math.max(0, volumeOverride ?? this.settings?.volume ?? 0.6)) * 0.25;
        const start = context.currentTime + 0.02;

        for (const note of PATTERNS[kind]) {
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const filter = context.createBiquadFilter();

            oscillator.type = note.type;
            oscillator.frequency.value = note.freq;
            filter.type = 'lowpass';
            filter.frequency.value = note.type === 'square' ? 1400 : 5000;

            // A soft attack and an exponential tail: no clicks, nothing harsh.
            gain.gain.setValueAtTime(0.0001, start + note.at);
            gain.gain.exponentialRampToValueAtTime(volume, start + note.at + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + note.at + note.length);

            oscillator.connect(filter).connect(gain).connect(context.destination);
            oscillator.start(start + note.at);
            oscillator.stop(start + note.at + note.length + 0.05);
        }
    }
}

export const dashboardSound = new DashboardSound();
