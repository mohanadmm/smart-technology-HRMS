<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

use RuntimeException;

/** A day laborer has no leave balance to adjust; rendered in bootstrap/app.php as 422 `no_leave_balance`. */
final class NoLeaveBalance extends RuntimeException {}
