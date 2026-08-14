<?php

namespace Kayedspace\Erpnext\Documents;

class Item extends Document
{
    public static function doctype(): string
    {
        return 'Item';
    }

    public function itemCode(): ?string
    {
        return $this->get('item_code');
    }

    /**
     * Accounting context an item can carry about itself, so a predefined item is
     * self-describing and application settings only ever act as a fallback.
     */
    public function company(): ?string
    {
        return $this->get('custom_company') ?: null;
    }

    public function costCenter(): ?string
    {
        return $this->get('custom_business_unit') ?: null;
    }
}
