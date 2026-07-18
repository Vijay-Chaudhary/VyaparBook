<?php
// app/Import/CsvReader.php

namespace App\Import;

use RuntimeException;

/**
 * Reads a CSV file into an iterable of header-keyed, trimmed row arrays.
 *
 * Native fgetcsv — no new dependency. The first line is the header; every
 * subsequent line is array_combine'd against it so the importer works with
 * ['name' => ..., 'village' => ...] rows regardless of column order.
 */
class CsvReader
{
    /**
     * @return iterable<int, array<string, string>>
     */
    public function rows(string $path): iterable
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === null) {
                return; // empty file — no rows
            }

            $header = array_map(fn ($h) => trim((string) $h), $header);

            while (($row = fgetcsv($handle)) !== false) {
                // fgetcsv yields [null] for a fully blank trailing line.
                if ($row === [null]) {
                    continue;
                }

                // Pad/trim the row to the header width so array_combine never
                // throws on a short or ragged line.
                $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
                $values = array_map(fn ($v) => trim((string) $v), $row);

                yield array_combine($header, $values);
            }
        } finally {
            fclose($handle);
        }
    }
}
