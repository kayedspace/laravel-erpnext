<?php

namespace Kayedspace\Erpnext\Documents;

class Company extends Document
{
    public static function doctype(): string
    {
        return 'Company';
    }

    public function defaultCurrency(): ?string
    {
        return $this->get('default_currency') ?: null;
    }

    public function abbreviation(): ?string
    {
        return $this->get('abbr');
    }
}
