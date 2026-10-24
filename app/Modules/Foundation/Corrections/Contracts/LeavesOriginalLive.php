<?php

namespace App\Modules\Foundation\Corrections\Contracts;

/**
 * ⭐ Optional marker, beside DocumentCorrector (corrections wave 1 gaps,
 * 24 Oct 2026): this correction ISSUES a linked document against an original
 * that stays live (a price credit note, an extra charge on a sales invoice).
 *
 * The original is not reversed and not marked, and may receive more of them
 * later, so the correction link is written WITHOUT a `live_key` (that unique
 * key means "this document was reversed or corrected" and allows one). The
 * link still carries its `idempotency_key` (UNIQUE), so a double click or a
 * retry never issues a second document; and the corrector's fingerprint
 * changes with every document issued, so a second dialog opened on the old
 * state is refused as stale.
 *
 * CorrectionChain::linkAsOriginal ignores such links: they are not a step of
 * the original's correction chain.
 */
interface LeavesOriginalLive {}
