<?php
// app/Import/ImportReport.php

namespace App\Import;

/**
 * Mutable tally of an import run: how many rows were created, updated, or
 * skipped, plus the per-row error messages behind every skip.
 */
class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int, array{row: int, message: string}> */
    public array $errors = [];

    public function addError(int $row, string $message): void
    {
        $this->errors[] = ['row' => $row, 'message' => $message];
        $this->skipped++;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function summaryLine(): string
    {
        return "Created: {$this->created}  Updated: {$this->updated}  Skipped: {$this->skipped}";
    }
}
