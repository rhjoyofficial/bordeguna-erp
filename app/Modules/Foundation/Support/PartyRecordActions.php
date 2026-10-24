<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Models\Customer;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 3.2 / 3.3 "Customer, Supplier: Delete to the
 * bin if never used. Reactivate."): what a customer's or supplier's page shows
 * about the record itself, each button allowed or greyed with its sentence.
 *
 * ⚠ Asked for ONE record (its page), never per row of a list: the "used" check
 * reads every table that can point at it.
 */
final class PartyRecordActions
{
    public static function for(Customer|Supplier $party, User $user): array
    {
        $kind = $party instanceof Customer ? 'customer' : 'supplier';
        $manage = $user->can($kind === 'customer' ? 'sales.customers.manage' : 'purchasing.suppliers.manage');
        $prefix = $kind === 'customer' ? 'sales.customers.' : 'purchasing.suppliers.';
        $merged = $party->merged_into_id === null ? null : $party->mergedInto()->first(['id', 'name']);

        return [
            'kind' => $kind,
            'may_manage' => $manage,
            'is_active' => (bool) $party->is_active,
            'reactivate_url' => route($prefix.'reactivate', $party->getKey()),
            'reactivate_blocked' => $manage ? $party->whyNotReactivatable() : 'You may not change this list.',
            'delete_url' => route($prefix.'bin', $party->getKey()),
            'delete_blocked' => $manage ? $party->whyNotRemovable() : 'You may not change this list.',
            'merged_into' => $merged === null ? null : [
                'name' => $merged->name,
                'url' => route($prefix.'show', $merged->getKey()),
            ],
            'reasons' => CorrectionScreen::reasons('deletion'),
        ];
    }
}
