<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use stdClass;

/** Everything that varies per PdfType; PdfExports::document() picks the class. */
interface PdfDocument
{
    /** @return array<string, list<string>> Laravel rules for the keys of the form's fields */
    public function rules(): array;

    /**
     * The validated fields as the snapshot stores them (optional keys filled, casts applied); a replay compares these.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function normalise(array $fields): array;

    /**
     * Locks the subject row, checks reach and builds the snapshot.
     *
     * @param  array<string, mixed>  $fields  normalised
     * @return array{0: string, 1: array<string, mixed>} [employee_id, snapshot]
     */
    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array;

    /** The hr_form_exports column (or snapshot path) that holds the subject id. */
    public function subjectColumn(): string;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: view-string, 1: array<string, mixed>} [view name, type-specific view data]
     */
    public function view(array $snapshot): array;

    /** The stamp line for the signed snapshot (null = none); throws the refusal when signable() refuses $actor. $lock: approve's transaction locks the subject row, a read does not. */
    public function approval(stdClass $export, Authenticatable $actor, bool $lock): ?string;

    /** The signer rule, stated once: narrows hr_form_exports rows of this type to those $actor may sign, so the awaiting-approval list filters in SQL. */
    public function signable(Builder $exports, Authenticatable $actor): Builder;
}
