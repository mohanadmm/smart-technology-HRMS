<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * Masks what the request and error logs store: a key rule (secrets by substring, money and IDs by exact name) at any depth,
 * then a shape rule (a run of 7+ digits) on every remaining string, keys included. The spec's "Masking" section is the source.
 */
final class LogMasker
{
    public const MASK = '[masked]';

    private const SECRET_FRAGMENTS = ['password', 'token', 'secret', 'authorization', 'cookie'];

    /** Exact names, not fragments: `pay`/`rate`/`basic` would also hide `pay_method`, `allowance_mode` and `fares`. */
    private const MONEY_AND_ID_KEYS = ['salary', 'raise', 'insured_basic', 'daily_wage', 'value', 'amount', 'current_pay', 'national_id',
        'insurance_no', 'bank_account', 'phone'];

    private const MAX_BYTES = 65536;

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function data(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            // A numeric key string ("0") turns back into an int key, so lists stay lists.
            $out[self::text((string) $key)] = match (true) {
                self::sensitive(strtolower((string) $key)) => self::MASK,
                is_array($value) => self::data($value),
                is_string($value) => self::text($value),
                default => $value,
            };
        }

        return $out;
    }

    public static function text(string $text): string
    {
        return (string) preg_replace('/\d{7,}/', self::MASK, (string) mb_scrub(str_replace("\0", '', $text), 'UTF-8'));
    }

    /**
     * @param  array<mixed>|null  $data
     *
     * @throws \JsonException
     */
    public static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        // NULs are already gone from every key and string (text()), so no `\u0000` reaches Postgres jsonb.
        $json = json_encode(self::data($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return strlen($json) > self::MAX_BYTES
            ? json_encode(['_truncated' => true, 'bytes' => strlen($json)], JSON_THROW_ON_ERROR)
            : $json;
    }

    private static function sensitive(string $key): bool
    {
        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return in_array($key, self::MONEY_AND_ID_KEYS, true) || str_ends_with($key, '_amount');
    }
}
