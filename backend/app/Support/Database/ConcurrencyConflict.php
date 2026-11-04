<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/** A stale `version` on an optimistically locked row; rendered in bootstrap/app.php as 409 `concurrency_conflict` (standard §7). */
final class ConcurrencyConflict extends RuntimeException
{
    public const CODE = 'concurrency_conflict';

    /** @param  list<array{field: string, code: string, message: string}>  $details  the error envelope's details (a bulk write: which rows) */
    public function __construct(public readonly array $details = [])
    {
        parent::__construct('Concurrency conflict');
    }
}
