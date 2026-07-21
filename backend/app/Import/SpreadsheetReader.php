<?php
// app/Import/SpreadsheetReader.php

namespace App\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Reads a spreadsheet (.xlsx/.xls/.ods) into the same header-keyed, trimmed
 * string rows CsvReader produces, so TenantImporter cannot tell them apart.
 *
 * Format is auto-detected rather than assumed from the extension: a shop's
 * "customers.xlsx" is quite often a renamed .xls, and IOFactory sniffs the
 * actual container.
 *
 * NOTE ON setReadDataOnly(): deliberately NOT enabled. It skips cell formatting,
 * and date detection depends on the number format — with it on, every date cell
 * silently arrives as its serial (45292 instead of 2024-01-01). Onboarding files
 * are small, so correctness wins over the memory saving.
 */
class SpreadsheetReader implements SheetReader
{
    /**
     * @return iterable<int, array<string, string>>
     */
    public function rows(string $path): iterable
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot open spreadsheet file: {$path}");
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (ReaderException $e) {
            throw new RuntimeException("Unrecognised spreadsheet format: {$path}", 0, $e);
        }

        // Read the first sheet only. A shop's workbook routinely carries extra
        // tabs (notes, last year's data); silently concatenating them would
        // import rows nobody asked for.
        $sheet = $reader->load($path)->getActiveSheet();

        $columns = null; // ['A' => 'name', 'C' => 'village'] — empty headers dropped

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCellIterator();
            // Include blank cells, or a gap would shift later values into the
            // wrong column.
            $cells->setIterateOnlyExistingCells(false);

            /** @var array<string, string> $values keyed by column letter */
            $values = [];
            foreach ($cells as $cell) {
                $values[$cell->getColumn()] = $this->stringify($cell);
            }

            if ($columns === null) {
                $columns = array_filter($values, fn (string $header) => $header !== '');

                if ($columns === []) {
                    return; // no usable header row — no rows
                }

                continue;
            }

            $rowValues = [];
            foreach ($columns as $column => $header) {
                $rowValues[$header] = $values[$column] ?? '';
            }

            // Skip fully blank rows: spreadsheets carry trailing formatted-but-
            // empty rows that would otherwise become "name is required" errors.
            if (implode('', $rowValues) === '') {
                continue;
            }

            yield $rowValues;
        }
    }

    /**
     * Collapse a cell to the string the importer expects.
     *
     * Excel's native types are the whole hazard here: a phone number is stored
     * as a float and casts to "9.87654321E+9", an opening balance of 250.00
     * becomes "250.0", and a date becomes a serial. Each is silently wrong
     * rather than loudly broken, which is why this is explicit per type.
     */
    private function stringify(Cell $cell): string
    {
        $value = $this->value($cell);

        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return '';
            }

            // A date cell reaches here as a serial unless the number format says
            // otherwise — convert before it looks like an ordinary number.
            if (ExcelDate::isDateTime($cell)) {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            }

            // Integral values (phones, counts, whole rupees) must render as plain
            // digits: no exponent, no ".0" tail.
            if ($value === floor($value) && abs($value) < 1e15) {
                return number_format($value, 0, '.', '');
            }

            // Fractional: fixed notation, then drop the padding zeros so "250.50"
            // arrives as "250.5" rather than "250.5000000000".
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * A formula cell's value is its result, not its "=SUM(...)" source. Excel
     * caches the last computed result; prefer that over re-evaluating, since a
     * formula referencing another sheet (or an unsupported function) throws.
     */
    private function value(Cell $cell): mixed
    {
        if (! $cell->isFormula()) {
            return $cell->getValue();
        }

        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable) {
            return $cell->getOldCalculatedValue();
        }
    }
}
