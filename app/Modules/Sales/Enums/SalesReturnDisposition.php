<?php

namespace App\Modules\Sales\Enums;

/**
 * What happens to returned goods after inspection (C-SR-3, Joy 2026-08-12):
 * the person recording the return chooses per line, and one line may split.
 *
 * ⚠ Disposition decides where the COST goes and nothing else - the customer's
 * credit is identical either way. Written-off goods must never re-enter the
 * stock asset, or the balance sheet carries worthless bottles at full cost.
 */
enum SalesReturnDisposition: string
{
    case Stock = 'stock';
    case WriteOff = 'write_off';
}
