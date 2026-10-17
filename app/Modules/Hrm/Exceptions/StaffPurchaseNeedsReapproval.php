<?php

namespace App\Modules\Hrm\Exceptions;

use RuntimeException;

/**
 * Thrown inside the handover transaction when the batch reserved and priced
 * at approval no longer holds (it left, was held, expired, or its cost
 * changed). The transaction rolls back; the controller then sends the request
 * back for re-approval with this reason. Never silently recompute the price
 * (ChatGPT ruling Q1).
 */
class StaffPurchaseNeedsReapproval extends RuntimeException {}
