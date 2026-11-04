<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

use RuntimeException;

/** Rendered in bootstrap/app.php as 422 with the reason's code. */
final class LeaveRefused extends RuntimeException
{
    /** @param  array<string, string>  $params  lang placeholders */
    public function __construct(public readonly LeaveRefusal $reason, public readonly array $params = [])
    {
        parent::__construct('Leave refused: '.$reason->value);
    }
}
