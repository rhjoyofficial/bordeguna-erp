<?php

namespace App\Modules\Purchasing\Models\Concerns;

/**
 * PUR-003's Reversible, promoted to App\Modules\Foundation\Concerns\Reversible
 * (edit, delete and correct, Phase 0, 17 Oct 2026) so every module's money
 * documents share one "kept and marked" shape. This thin alias keeps the
 * Purchasing models and their imports unchanged.
 */
trait Reversible
{
    use \App\Modules\Foundation\Concerns\Reversible;
}
