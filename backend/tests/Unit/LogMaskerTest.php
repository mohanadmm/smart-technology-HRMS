<?php

namespace Tests\Unit;

use App\Modules\Audit\Domain\LogMasker;
use PHPUnit\Framework\TestCase;

class LogMaskerTest extends TestCase
{
    public function test_secret_keys_are_masked_by_substring(): void
    {
        $in = ['password' => 'x', 'new_password_confirmation' => 'x', 'Authorization' => 'x', 'access_token' => 'x', 'token_type' => 'Bearer'];

        $this->assertSame(array_fill_keys(array_keys($in), '[masked]'), LogMasker::data($in));
    }

    public function test_money_and_id_keys_are_masked_exactly(): void
    {
        $masked = ['salary', 'raise', 'insured_basic', 'daily_wage', 'value', 'amount', 'current_pay', 'national_id', 'insurance_no',
            'bank_account', 'phone', 'advance_amount', 'outside_amount'];
        $kept = ['pay_method' => 'bank', 'allowance_mode' => 'percent', 'kind' => 'fixed', 'effective_from' => '2026-01-01'];

        $out = LogMasker::data([...array_fill_keys($masked, '150'), ...$kept]);

        $this->assertSame([...array_fill_keys($masked, '[masked]'), ...$kept], $out);
    }

    public function test_nested_and_list_values_are_masked(): void
    {
        $out = LogMasker::data(['rows' => [['advance_amount' => '50', 'note' => 'x'], ['employee' => ['salary' => '9000']]]]);

        $this->assertSame(['rows' => [['advance_amount' => '[masked]', 'note' => 'x'], ['employee' => ['salary' => '[masked]']]]], $out);
    }

    public function test_long_numbers_are_masked_and_short_scalars_keep_their_type(): void
    {
        $kept = ['short' => 12, 'f' => 1.5, 'b' => true, 'z' => null, 'list' => [1, 2]];
        $in = ['n' => 12345678, 'nid' => 29801011234567, 'big_float' => 29801011234567.0, ...$kept];

        $this->assertSame(['n' => '[masked]', 'nid' => '[masked]', 'big_float' => '[masked]', ...$kept], LogMasker::data($in));
    }

    public function test_secret_check_runs_on_the_cleaned_key(): void
    {
        $this->assertSame(['password' => '[masked]'], LogMasker::data(["pass\x00word" => 'hunter2']));
    }

    public function test_digit_runs_are_masked_in_any_string(): void
    {
        $this->assertSame(['search' => '[masked]'], LogMasker::data(['search' => '29801011234567']));
        $this->assertSame(['q' => 'call [masked] now'], LogMasker::data(['q' => 'call 01012345678 now']));
        $kept = ['date' => '2026-11-04', 'code' => '123456'];
        $this->assertSame($kept, LogMasker::data($kept));
    }

    public function test_uuids_are_kept_whole(): void
    {
        $uuid = 'ad93e478-b02c-4113-9017-2fc1234567fa';

        $this->assertSame($uuid, LogMasker::text($uuid));
        $this->assertSame(strtoupper($uuid), LogMasker::text(strtoupper($uuid)));
        $this->assertSame("api/v1/sites/$uuid/fares", LogMasker::text("api/v1/sites/$uuid/fares"));
        $this->assertSame(['employee_id' => $uuid], LogMasker::data(['employee_id' => $uuid]));
        $this->assertSame("dup [masked] for $uuid", LogMasker::text("dup 29801011234567 for $uuid"));
        // A digit run glued to a UUID is not a UUID: it is masked, not passed through.
        $this->assertSame('[masked]', LogMasker::text('123456789012345678'));
        $this->assertStringNotContainsString('1234567890', LogMasker::text('ad93e478-b02c-4113-9017-123456789012345678'));
        // Arabic-Indic digits glued on either side make it no UUID either.
        $this->assertStringNotContainsString('123456789', LogMasker::text('ad93e478-b02c-4113-9017-2fc123456789٠١٢٣٤'));
        $this->assertStringNotContainsString('٠١٢٣٤', LogMasker::text('ad93e478-b02c-4113-9017-2fc123456789٠١٢٣٤'));
        $this->assertStringNotContainsString('1234567', LogMasker::text('٠١٢٣٤٥ad93e478-b02c-4113-9017-2fc1234567fa'));
    }

    public function test_arabic_indic_digit_runs_are_masked(): void
    {
        $this->assertSame(['search' => '[masked]'], LogMasker::data(['search' => '٢٩٨٠١٠١١٢٣٤٥٦٧']));
        $this->assertSame(['q' => 'id [masked]'], LogMasker::data(['q' => 'id ۰۱٠12345']));
        $this->assertSame(['code' => '١٢٣٤٥٦'], LogMasker::data(['code' => '١٢٣٤٥٦']));
    }

    public function test_keys_are_cleaned_and_shape_masked(): void
    {
        // PHP turns the numeric key into an int; it must still not reach the log.
        $this->assertSame(['[masked]' => 'x', 'ab' => 'y'], LogMasker::data(['29801011234567' => 'x', "a\x00b" => 'y']));
        $this->assertSame('{"note":"\\\\u0000"}', LogMasker::encode(['note' => '\u0000']));
    }

    public function test_encode_null_stays_null(): void
    {
        $this->assertNull(LogMasker::encode(null));
    }

    public function test_encode_survives_bad_bytes(): void
    {
        $json = LogMasker::encode(['note' => "a\x00b\xC3\x28"]);

        $this->assertIsString($json);
        $this->assertIsArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('\u0000', $json);
        $this->assertStringNotContainsString("\x00", $json);
    }

    public function test_encode_truncates_large_bodies(): void
    {
        $out = json_decode((string) LogMasker::encode(['note' => str_repeat('a', 70000)]), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($out);
        $this->assertSame(['_truncated', 'bytes'], array_keys($out));
        $this->assertTrue($out['_truncated']);
        $this->assertIsInt($out['bytes']);
        $this->assertGreaterThan(65536, $out['bytes']);
    }

    public function test_text_masks_and_cleans(): void
    {
        $this->assertSame('dup [masked]', LogMasker::text("dup 29801011234567\x00"));
    }
}
