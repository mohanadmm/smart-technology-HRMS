<?php

return [
    'refusal' => [
        'leave_day_laborer' => 'Day laborers have no leave balance and cannot request leave.',
        'leave_overlap' => 'Another leave request of this employee covers some of these days.',
        'leave_no_days' => 'No day in this range counts as leave: weekly offs and official holidays are not counted.',
        'leave_crosses_year' => 'A leave request cannot cross December 31. File one request for each year.',
        'leave_too_long' => 'A leave request covers at most :max days.',
        'site_not_in_reach' => 'This employee is not in your reach on some of these dates.',
        'leave_days_blocked' => 'Requested days that already hold a work day or are in a locked period: :count.',
        'leave_cancel_blocked' => 'Leave days to clear hold a note or an advance; change them in attendance first: :count.',
    ],
];
