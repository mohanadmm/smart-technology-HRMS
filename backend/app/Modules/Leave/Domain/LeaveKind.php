<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

/** What a leave request asks for; each value is also the leave code its days are written with. */
enum LeaveKind: string
{
    case Annual = 'annual';
    case Casual = 'casual';
    case Sick = 'sick';
    case Unpaid = 'unpaid';
}
