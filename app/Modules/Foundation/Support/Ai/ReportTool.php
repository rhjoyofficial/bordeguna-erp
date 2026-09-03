<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;

/**
 * হুদহুদ's door into the reporting engine (Module 7, Slice 3).
 *
 * The assistant can LIST the reports a person may already run, and RUN one
 * with simple parameters - the same definitions, the same permission per
 * report, the same rows the screen would show. Nothing here computes: the
 * engine answers, হুদহুদ narrates.
 *
 * ⚠ Rows are CAPPED. The assistant summarises; a person who wants all the
 * rows exports from the Reports screen, where the writers and the
 * letterhead live.
 */
class ReportTool
{
    private const MAX_ROWS = 40;

    public function __construct(private readonly ReportRegistry $registry) {}

    /** @return array<int, array{key: string, name: string, description: string}> */
    public function available(User $user): array
    {
        return $this->registry->all()
            ->filter(fn ($definition) => $user->can($definition->permission()))
            ->map(fn ($definition) => [
                'key' => $definition->key(),
                'name' => $definition->name(),
                'description' => $definition->description(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(User $user, array $input): array
    {
        $key = (string) ($input['report'] ?? '');
        $definition = $this->registry->all()->first(fn ($d) => $d->key() === $key);

        if ($definition === null) {
            throw new AssistantRefusal(
                'No report is named "'.$key.'". Ask for the report list first.',
            );
        }

        // ⚠ The report's OWN permission, per person - the registry gate the
        // Reports screen already enforces, enforced again here.
        if (! $user->can($definition->permission())) {
            throw new AssistantRefusal(
                'You do not have permission to run "'.$definition->name().'".',
            );
        }

        $parameters = new ReportParameters(
            collect($input)->except(['report'])->map(fn ($value) => (string) $value)->all(),
        );

        $rows = [];
        $truncated = false;
        foreach ($definition->rows($parameters) as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        return [
            'report' => $definition->name(),
            'subtitle' => $definition->meta($parameters)->subtitle,
            'columns' => array_map(fn ($column) => $column->label, $definition->columns($parameters)),
            'rows' => $rows,
            'truncated' => $truncated,
            'note' => $truncated
                ? 'Only the first '.self::MAX_ROWS.' rows are shown - export the full report from the Reports screen.'
                : null,
        ];
    }
}
