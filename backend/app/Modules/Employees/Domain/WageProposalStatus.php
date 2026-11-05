<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

/** A site manager's proposed daily wage, as HR decides it (`employee_wage_proposals.status`). */
enum WageProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';
}
