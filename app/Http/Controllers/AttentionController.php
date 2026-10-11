<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\NeedsAttention;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Needs attention (plan C.4): every item this person may see, ranked, with
 * filters by severity, area, brand and "mine".
 *
 * ⚠ The page is open to every member of the business: what is ON it is
 * decided per source, by that source's own permission (approvals waiting on
 * you need none). Filters narrow what was authorised, never widen it.
 */
class AttentionController extends Controller
{
    public function __construct(
        private readonly NeedsAttention $attention,
        private readonly BusinessContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'severity' => ['nullable', Rule::in(array_keys(AttentionItem::RANK))],
            'area' => ['nullable', Rule::in(AttentionItem::AREAS)],
            'brand' => ['nullable', 'string', 'max:20'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $businessId = (int) $this->context->id();
        ['items' => $items, 'failed' => $failed] = $this->attention->for($user, $businessId);

        $roles = $user->getRoleNames()->all();
        $brandsKnown = NeedsAttention::brandsKnown();
        // ⚠ Through OrderBrand::filter: a brand of another business reads as
        // all brands, never as a narrowing (business isolation).
        $brand = $brandsKnown ? OrderBrand::filter($filters['brand'] ?? null, $businessId) : null;
        $brandNames = $brandsKnown ? OrderBrand::names($businessId) : [];

        $shown = array_values(array_filter($items, function (AttentionItem $item) use ($filters, $user, $roles, $brand) {
            return (blank($filters['severity'] ?? null) || $item->severity === $filters['severity'])
                && (blank($filters['area'] ?? null) || $item->area === $filters['area'])
                && $item->matchesBrand($brand)
                && (! ($filters['mine'] ?? false) || NeedsAttention::isMine($item, $user, $roles));
        }));

        $count = fn (string $severity) => count(array_filter($items, fn (AttentionItem $item) => $item->severity === $severity));

        return Inertia::render('attention', [
            'items' => array_map(fn (AttentionItem $item) => $item->toArray() + [
                'mine' => NeedsAttention::isMine($item, $user, $roles),
                'brand' => $item->brandId === null ? null : ($brandNames[$item->brandId] ?? null),
            ], $shown),
            'total' => count($items),
            'counts' => [
                AttentionItem::CRITICAL => $count(AttentionItem::CRITICAL),
                AttentionItem::HIGH => $count(AttentionItem::HIGH),
                AttentionItem::NORMAL => $count(AttentionItem::NORMAL),
            ],
            'areas' => array_values(array_unique(array_map(fn (AttentionItem $item) => $item->area, $items))),
            'failed' => $failed,
            'brands' => $brandsKnown ? OrderBrand::options($businessId)->all() : null,
            'filters' => [
                'severity' => $filters['severity'] ?? null,
                'area' => $filters['area'] ?? null,
                'brand' => $brand === null ? null : (string) $brand,
                'mine' => (bool) ($filters['mine'] ?? false),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
