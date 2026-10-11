<?php

namespace App\Modules\Sales\Reporting\Concerns;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Sales\Support\OrderBrand;

/**
 * The brand filter every sales report shares: All, "No brand", or one brand
 * of THIS business (OrderBrand's rule: a brand id of another business reads as
 * all brands and never narrows, widens or reveals anything).
 */
trait FiltersByOrderBrand
{
    protected function businessId(): int
    {
        return (int) app(BusinessContext::class)->id();
    }

    protected function brandParameter(): ReportParameter
    {
        $options = [['value' => OrderBrand::NONE, 'label' => 'No brand']];

        foreach (OrderBrand::options($this->businessId()) as $brand) {
            $options[] = ['value' => (string) $brand['id'], 'label' => $brand['name']];
        }

        return ReportParameter::select('brand', 'Brand', $options);
    }

    protected function brandFilter(ReportParameters $parameters): int|string|null
    {
        return OrderBrand::filter($parameters->string('brand'), $this->businessId());
    }

    /**
     * Brand names of this business, read once per run by the caller. Never
     * kept on the definition: it is a singleton in the registry, and a queue
     * worker would otherwise label rows with a brand's old name, or another
     * business's.
     *
     * @return array<int, string>
     */
    protected function brandNames(): array
    {
        return OrderBrand::names($this->businessId());
    }

    /** @param  array<int, string>  $names */
    protected static function brandLabel(?int $brandId, array $names): string
    {
        return $brandId === null ? 'No brand' : ($names[$brandId] ?? 'No brand');
    }

    protected function periodSubtitle(ReportParameters $parameters, string $key, string $whole): string
    {
        $from = $parameters->from($key);
        $to = $parameters->to($key);

        return match (true) {
            $from !== null && $to !== null => $from->format('d M Y').' to '.$to->format('d M Y'),
            $from !== null => 'From '.$from->format('d M Y'),
            $to !== null => 'Up to '.$to->format('d M Y'),
            default => $whole,
        };
    }
}
