<?php

namespace App\Modules\Sales\Support\CourierStatements;

use RuntimeException;

/** One row of a courier's file that cannot be read. The rest of the file still is. */
final class StatementRowException extends RuntimeException {}
