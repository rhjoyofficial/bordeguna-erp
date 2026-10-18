<?php

/*
 * ⚠ P10 (18 Oct 2026, practice run). Only what this ERP says differently from
 * Laravel's own lines: the framework file is loaded first and this one is laid
 * over it, so every other message stays Laravel's.
 *
 * `:position` is the line number counted from one (Laravel fills it in from
 * `lines.0.product_id`). Field names for every other array field come from
 * App\Support\Validation\ArrayFieldName ("line 1 unit price").
 */

$lineSentences = [
    'product_id' => [
        'required' => 'Line :position: choose a product.',
        'exists' => 'Line :position: that product is not in this business. Choose it again from the list.',
    ],
    'quantity' => [
        'required' => 'Line :position: enter the quantity.',
        'numeric' => 'Line :position: the quantity must be a number, for example 20 or 2.5.',
        'gt' => 'Line :position: the quantity must be more than :value.',
        'min' => 'Line :position: the quantity cannot be less than :min.',
    ],
    'unit_price' => [
        'required' => 'Line :position: enter the unit price.',
        'numeric' => 'Line :position: the unit price must be a number, for example 801 or 12.50.',
        'min' => 'Line :position: the unit price cannot be less than :min.',
    ],
    'unit_cost' => [
        'required' => 'Line :position: enter the unit cost.',
        'numeric' => 'Line :position: the unit cost must be a number, for example 801 or 12.50.',
    ],
    'waste' => [
        'numeric' => 'Line :position: the waste must be a number, or leave it empty.',
        'min' => 'Line :position: the waste cannot be less than :min.',
    ],
];

$custom = [];
foreach (['lines', 'items'] as $array) {
    foreach ($lineSentences as $field => $rules) {
        $custom["{$array}.*.{$field}"] = $rules;
    }
}

return [
    'custom' => $custom + [
        'output_quantity' => [
            'required' => 'Enter how much was produced.',
            'numeric' => 'Enter the output quantity as a number, for example 20 or 2.5.',
            'gt' => 'The output quantity must be more than :value.',
        ],
    ],
];
