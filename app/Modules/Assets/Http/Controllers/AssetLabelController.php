<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Support\AssetQrCode;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * QR tag stickers for the register (asset management slice A1, plan §2.8).
 *
 * ⚠ A bare print page, the pattern the product barcode labels and every other
 * printed document here follow: the browser's print dialog IS the download.
 *
 * ⚠ Each label carries the tag in words as well as the code, so an item can be
 * found by typing when a phone camera is not at hand.
 */
class AssetLabelController extends Controller
{
    /** Sticker sizes in millimetres, with the side of the QR code on each. */
    public const SIZES = [
        'small' => ['label' => 'Small - 38 x 21 mm', 'w' => 38, 'h' => 21, 'qr' => 17],
        'medium' => ['label' => 'Medium - 50 x 30 mm', 'w' => 50, 'h' => 30, 'qr' => 24],
        'large' => ['label' => 'Large - 70 x 40 mm', 'w' => 70, 'h' => 40, 'qr' => 32],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('assets/items/labels', [
            'items' => AssetItem::query()
                ->with('category:id,name')
                ->where('status', '!=', AssetStatus::Disposed->value)
                ->orderBy('tag')
                ->get(['id', 'tag', 'name', 'asset_category_id'])
                ->map(fn (AssetItem $item) => [
                    'id' => $item->id,
                    'tag' => $item->tag,
                    'name' => $item->name,
                    'category_id' => $item->asset_category_id,
                    'category' => $item->category?->name,
                ]),
            'categories' => AssetCategory::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'sizes' => collect(self::SIZES)->map(fn (array $size, string $key) => ['value' => $key, 'label' => $size['label']])->values(),
            'preselected' => array_map('intval', (array) $request->query('ids', [])),
        ]);
    }

    public function print(Request $request): Response
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'size' => ['required', 'in:'.implode(',', array_keys(self::SIZES))],
            'copies' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $size = self::SIZES[$data['size']];
        $copies = (int) ($data['copies'] ?? 1);
        $business = Business::query()->find(app(BusinessContext::class)->id());

        $labels = [];

        foreach (AssetItem::query()->whereIn('id', $data['ids'])->orderBy('tag')->get() as $item) {
            $svg = AssetQrCode::svg($item->tagUrl());

            for ($i = 0; $i < $copies; $i++) {
                $labels[] = [
                    'tag' => $item->tag,
                    'name' => $item->name,
                    'svg' => $svg,
                ];
            }
        }

        return Inertia::render('assets/items/labels-print', [
            'labels' => $labels,
            'size' => $size,
            'business' => $business?->name,
        ]);
    }
}
