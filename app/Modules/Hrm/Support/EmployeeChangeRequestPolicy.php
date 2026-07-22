<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\Employee;

/**
 * Which of an employee's own fields they may request to change through ESS, and
 * which approval workflow each group routes to (Joy's policy, 2026-07-21):
 * personal/contact/emergency → HR; bank / MFS → HR + Finance. Employment details
 * (department, designation, reporting manager, joining/confirmation, employment
 * type) are HR-only and edited directly by HR, not requestable here; salary is
 * out of ESS entirely (Payroll). This is the HR-specific policy — the generic
 * ChangeRequest engine knows none of it.
 */
class EmployeeChangeRequestPolicy
{
    private const GROUPS = [
        'personal' => [
            'label' => 'Contact details',
            'workflow' => 'employee_personal_change',
            'fields' => [
                'personal_email', 'phone', 'present_address', 'permanent_address',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
            ],
        ],
        'bank' => [
            'label' => 'Bank & Mobile Financial Service',
            'workflow' => 'employee_bank_change',
            'fields' => [
                'bank_account_name', 'bank_account_number', 'bank_name', 'bank_branch', 'bank_routing_number',
                'mfs_provider', 'mfs_account_number',
            ],
        ],
    ];

    /** @return array<int, string> */
    public static function groupKeys(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function label(string $group): string
    {
        return self::GROUPS[$group]['label'];
    }

    public static function workflowFor(string $group): string
    {
        return self::GROUPS[$group]['workflow'];
    }

    /** @return array<int, string> */
    public static function fieldsFor(string $group): array
    {
        return self::GROUPS[$group]['fields'];
    }

    /**
     * Validation rules for one group's fields — the same EMP-04 rules HR uses,
     * narrowed to what this group exposes.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(string $group): array
    {
        return array_intersect_key(Employee::masterFieldRules(), array_flip(self::fieldsFor($group)));
    }

    /**
     * The groups as UI descriptors (key, label, and each field's label) so the
     * ESS page can render a request form per group without hardcoding fields.
     *
     * @return array<int, array{key: string, label: string, fields: array<int, array{name: string, label: string}>}>
     */
    public static function descriptors(): array
    {
        return collect(self::GROUPS)->map(fn (array $group, string $key) => [
            'key' => $key,
            'label' => $group['label'],
            'fields' => collect($group['fields'])
                ->map(fn (string $field) => ['name' => $field, 'label' => Employee::FIELD_LABELS[$field] ?? $field])
                ->all(),
        ])->values()->all();
    }
}
