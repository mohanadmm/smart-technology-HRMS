<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/** A stale `version` on an optimistically locked row; rendered in bootstrap/app.php as 409 `concurrency_conflict` (standard §7). */
final class ConcurrencyConflict extends RuntimeException
{
    public const CODE = 'concurrency_conflict';

    /** @var list<array{field: string, code: string, message: string}> the error envelope's details */
    public readonly array $details;

    /** @param  list<string>  $fields  the conflicting fields (a bulk write: `rows.N`) */
    public function __construct(array $fields = [])
    {
        parent::__construct('Concurrency conflict');
        $this->details = array_map(fn (string $field) => ['field' => $field, 'code' => self::CODE, 'message' => self::message()], $fields);
    }

    public static function message(): string
    {
        return (string) __('errors.'.self::CODE);
    }
}
