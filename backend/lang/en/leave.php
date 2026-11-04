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
        'leave_days_replaced' => 'Requested days that already hold another leave type: :count. Tick "Replace these days", or change the day\'s type in attendance first.',
        'leave_cancel_blocked' => 'Leave days holding a note or an advance: :count. Change them in attendance first.',
        'leave_employee_has_open_requests' => 'This employee has open leave requests: :count. Cancel or decide them first.',
    ],
];
