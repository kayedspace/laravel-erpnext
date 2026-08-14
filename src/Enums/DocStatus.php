<?php

namespace Kayedspace\Erpnext\Enums;

/**
 * Frappe's document lifecycle. Every document carries a `docstatus`; on doctypes that
 * are not submittable it simply never leaves {@see self::Draft}.
 *
 * The transitions Frappe permits are Draft → Submitted → Cancelled, one way. A
 * cancelled document is never edited again — it is amended, which creates a fresh
 * draft pointing back at it.
 */
enum DocStatus: int
{
    case Draft = 0;
    case Submitted = 1;
    case Cancelled = 2;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
