<?php
// app/Stock/MaterialUnit.php

namespace App\Stock;

/**
 * Single source of truth for raw-material units. The API validator and the CSV
 * importer both read this list, so it is defined exactly once — it was
 * previously copy-pasted into RawMaterialController and TenantImporter, which
 * is how the two could have drifted apart.
 *
 * `tina` is the sealed tin oil is bought and billed in (~15 kg). `bag` and
 * `dozen` are likewise how suppliers invoice, not conveniences: a shop that
 * cannot record the unit on the invoice ends up converting by hand, which is
 * where the arithmetic errors come from.
 */
final class MaterialUnit
{
    /** @return list<string> canonical order, used everywhere the list renders. */
    public static function keys(): array
    {
        return ['kg', 'gram', 'litre', 'ml', 'piece', 'packet', 'bag', 'dozen', 'tina'];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
