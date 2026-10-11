<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\NeedsAttention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'brand' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $businessId = (int) $this->context->id();
        ['items' => $items, 'failed' => $failed] = $this->attention->for($user, $businessId);

        $roles = $user->getRoleNames()->all();
        $brandsKnown = NeedsAttention::brandsKnown();

        $shown = array_values(array_filter($items, function (AttentionItem $item) use ($filters, $user, $roles, $brandsKnown) {
            return (blank($filters['severity'] ?? null) || $item->severity === $filters['severity'])
                && (blank($filters['area'] ?? null) || $item->area === $filters['area'])
                && (! $brandsKnown || blank($filters['brand'] ?? null) || $item->brandId === (int) $filters['brand'])
                && (! ($filters['mine'] ?? false) || NeedsAttention::isMine($item, $user, $roles));
        }));

        $count = fn (string $severity) => count(array_filter($items, fn (AttentionItem $item) => $item->severity === $severity));

        return Inertia::render('attention', [
            'items' => array_map(fn (AttentionItem $item) => $item->toArray() + ['mine' => NeedsAttention::isMine($item, $user, $roles)], $shown),
            'total' => count($items),
            'counts' => [
                AttentionItem::CRITICAL => $count(AttentionItem::CRITICAL),
                AttentionItem::HIGH => $count(AttentionItem::HIGH),
                AttentionItem::NORMAL => $count(AttentionItem::NORMAL),
            ],
            'areas' => array_values(array_unique(array_map(fn (AttentionItem $item) => $item->area, $items))),
            'failed' => $failed,
            'brands' => $brandsKnown ? $this->brands($businessId) : null,
            'filters' => [
                'severity' => $filters['severity'] ?? null,
                'area' => $filters['area'] ?? null,
                'brand' => isset($filters['brand']) ? (int) $filters['brand'] : null,
                'mine' => (bool) ($filters['mine'] ?? false),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** @return list<array{id: int, name: string}> */
    private function brands(int $businessId): array
    {
        return DB::table('brands')
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($brand) => ['id' => (int) $brand->id, 'name' => (string) $brand->name])
            ->all();
    }
}
