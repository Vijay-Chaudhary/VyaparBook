<?php
// app/Import/SheetReaderFactory.php

namespace App\Import;

/**
 * Picks the reader for a file so callers never branch on extension themselves.
 *
 * Extension chooses the *reader*, not the parse: SpreadsheetReader still sniffs
 * the real container, so a .xls misnamed .xlsx is handled. Anything unknown
 * falls back to CSV, which is the format an operator is most likely to hand-roll.
 */
class SheetReaderFactory
{
    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods'];

    public function for(string $path): SheetReader
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::SPREADSHEET_EXTENSIONS, true)
            ? new SpreadsheetReader()
            : new CsvReader();
    }
}
