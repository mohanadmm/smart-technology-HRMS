<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/** A stale `version` on an optimistically locked row; rendered in bootstrap/app.php as 409 `concurrency_conflict` (standard §7). */
final class ConcurrencyConflict extends RuntimeException {}
