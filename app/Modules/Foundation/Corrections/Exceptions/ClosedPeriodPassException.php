<?php

namespace App\Modules\Foundation\Corrections\Exceptions;

use RuntimeException;

/** A closed-month exception was used for something it does not cover. Always rolls back. */
class ClosedPeriodPassException extends RuntimeException {}
