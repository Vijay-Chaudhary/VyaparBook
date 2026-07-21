<?php
// app/Import/SheetReader.php

namespace App\Import;

/**
 * Reads a tabular file into an iterable of header-keyed, trimmed row arrays.
 *
 * The importer works entirely in strings (it validates with is_numeric and
 * trims), so every implementation MUST yield strings — never floats, ints or
 * DateTimes. That constraint is load-bearing for xlsx, where the underlying
 * library hands back native types: an unconverted phone number arrives as
 * 9.87654321E+9 and a date as the serial 45292.
 *
 * Implementations yield row-by-row, but that is not a memory guarantee: CsvReader
 * genuinely streams, while SpreadsheetReader must parse the whole workbook up
 * front (the format is a zip of interdependent XML parts). Onboarding files are
 * a few hundred rows, so this is fine — but do not point this at a huge sheet
 * expecting constant memory.
 */
interface SheetReader
{
    /**
     * @return iterable<int, array<string, string>>
     */
    public function rows(string $path): iterable;
}
