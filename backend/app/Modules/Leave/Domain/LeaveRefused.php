<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

use RuntimeException;

/** Rendered in bootstrap/app.php with the reason's status and code; `dates` (the blocked dates) goes into the error body when set. */
final class LeaveRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $params  lang placeholders
     * @param  list<string>  $dates
     */
    public function __construct(public readonly LeaveRefusal $reason, public readonly array $params = [], public readonly array $dates = [])
    {
        parent::__construct('Leave refused: '.$reason->value);
    }
}
