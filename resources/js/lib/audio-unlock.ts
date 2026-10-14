/**
 * One AudioContext for the whole app, unlocked by the first click or key
 * press (14 Oct 2026). Browsers refuse to make a sound before the person has
 * touched the page; the click on "Log in" counts, because the ERP stays on the
 * same document through Inertia, which is what lets the sign-in popup play.
 *
 * ⚠ Installed from app.tsx, so it is listening on the login page too. The
 * dashboard sounds used to register their own listener, but only the dashboard
 * pages imported that module, so on every other page audio stayed locked.
 *
 * ⚠ A sound asked for while audio is still locked is DROPPED, never queued:
 * a greeting that plays five minutes later, on an unrelated click, is a fright.
 */

let context: AudioContext | null = null;
let unlocked = false;
const samples = new Map<string, Promise<AudioBuffer | null>>();

export function audioContext(): AudioContext | null {
    if (context !== null) {
        return context;
    }

    if (typeof window === 'undefined') {
        return null;
    }

    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!Ctor) {
        return null;
    }

    context = new Ctor();

    return context;
}

export function isAudioUnlocked(): boolean {
    return unlocked;
}

/** Idempotent; the listeners remove themselves after the first gesture. */
export function installAudioUnlock(): void {
    if (typeof window === 'undefined' || unlocked) {
        return;
    }

    const unlock = () => {
        unlocked = true;
        audioContext()
            ?.resume()
            .catch(() => {});
        window.removeEventListener('pointerdown', unlock, true);
        window.removeEventListener('keydown', unlock, true);
    };

    window.addEventListener('pointerdown', unlock, true);
    window.addEventListener('keydown', unlock, true);
}

/** Marks audio usable without a gesture: a Test button's own click is one. */
export function markAudioUnlocked(): void {
    unlocked = true;
    audioContext()
        ?.resume()
        .catch(() => {});
}

/** Fetch and decode once per URL; null when the file cannot be played. */
export function loadSample(url: string): Promise<AudioBuffer | null> {
    const cached = samples.get(url);

    if (cached) {
        return cached;
    }

    const ctx = audioContext();
    const loading: Promise<AudioBuffer | null> =
        ctx === null || typeof fetch === 'undefined'
            ? Promise.resolve(null)
            : fetch(url, { credentials: 'same-origin' })
                  .then((response) => {
                      if (!response.ok) {
                          throw new Error(`HTTP ${response.status}`);
                      }

                      return response.arrayBuffer();
                  })
                  .then((bytes) => ctx.decodeAudioData(bytes))
                  .catch(() => null);

    samples.set(url, loading);

    return loading;
}

/**
 * Play a decoded sample at a level. Best effort: if audio is locked, or the
 * file is not ready within a moment of being asked for, nothing plays.
 */
export function playSample(url: string, level: number, maxWaitMs = 1500): void {
    if (!unlocked) {
        return;
    }

    const ctx = audioContext();

    if (ctx === null) {
        return;
    }

    const askedAt = Date.now();

    void loadSample(url).then((sample) => {
        if (sample === null || Date.now() - askedAt > maxWaitMs) {
            return;
        }

        ctx.resume().catch(() => {});

        const source = ctx.createBufferSource();
        const gain = ctx.createGain();

        source.buffer = sample;
        gain.gain.value = Math.min(1, Math.max(0, level));
        source.connect(gain).connect(ctx.destination);
        source.start(ctx.currentTime + 0.01);
    });
}
