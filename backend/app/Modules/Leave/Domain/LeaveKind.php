<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

/** What a leave request asks for; each value is also the leave code approval (Slice 5) writes the kept days with. A pending request writes nothing. */
enum LeaveKind: string
{
    case Annual = 'annual';
    case Casual = 'casual';
    case Sick = 'sick';
    case Unpaid = 'unpaid';
}
