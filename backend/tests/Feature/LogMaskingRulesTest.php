<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Audit\Domain\LogMasker;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Sites\Application\SiteRules;
use Illuminate\Support\Arr;
use Tests\TestCase;

/** The safety net for the exact money-key list: every key validated with the money or mobile pattern must come out masked. */
class LogMaskingRulesTest extends TestCase
{
    private const SENSITIVE_PATTERNS = ['\d{1,12}(\.\d{1,6})?', '01[0125]'];

    public function test_every_money_and_mobile_rule_key_is_masked(): void
    {
        $ruleSets = [
            'EmployeeRules::store' => EmployeeRules::store(),
            'EmployeeRules::pay' => EmployeeRules::pay(),
            'EmployeeRules::identity' => EmployeeRules::identity(false),
            'SiteRules::allowanceRule' => SiteRules::allowanceRule(),
            'SiteRules::fare' => SiteRules::fare(),
            'SiteRules::fareOverride' => SiteRules::fareOverride(),
            'AttendanceDays::FORMAT' => AttendanceDays::FORMAT,
            'AttendanceDays::BULK_FORMAT' => AttendanceDays::BULK_FORMAT,
        ];

        $checked = 0;
        foreach ($ruleSets as $set => $rules) {
            foreach ($rules as $key => $rule) {
                $text = implode(' ', array_map(fn ($r) => is_string($r) ? $r : '', Arr::flatten((array) $rule)));
                if (! array_filter(self::SENSITIVE_PATTERNS, fn ($p) => str_contains($text, $p))) {
                    continue;
                }
                $field = Arr::last(explode('.', (string) $key));
                $this->assertSame(LogMasker::MASK, LogMasker::data([$field => '15'])[$field], "$set: $key is not masked");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked, 'the pattern scan found too few keys; did the rule constants change?');
    }

    public function test_identity_keys_exist_and_are_masked(): void
    {
        $identity = EmployeeRules::identity(false);

        foreach (['national_id', 'insurance_no', 'bank_account', 'phone'] as $key) {
            $this->assertArrayHasKey($key, $identity);
            $this->assertSame(LogMasker::MASK, LogMasker::data([$key => '15'])[$key]);
        }
    }
}
