<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

use RuntimeException;

/** Another live employee holds the code; rendered as 409 `code_taken` naming the holder in the request's language in bootstrap/app.php. The caller may resend with swap. */
final class EmployeeCodeTaken extends RuntimeException
{
    public function __construct(public readonly int $taken, public readonly string $nameAr, public readonly ?string $nameEn)
    {
        parent::__construct('Employee code taken: '.$taken);
    }
}
