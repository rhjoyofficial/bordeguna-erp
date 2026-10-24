<?php

namespace App\Modules\Hrm\Corrections;

use InvalidArgumentException;

/**
 * The record changed after the second person was asked (FINAL-PLAN 6.6, item
 * 7): the waiting request fails instead of acting on what is no longer true.
 */
class StaleHrRecord extends InvalidArgumentException {}
