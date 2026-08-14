<?php

namespace Kayedspace\Erpnext\Documents;

class Customer extends Document
{
    public static function doctype(): string
    {
        return 'Customer';
    }

    public function customerName(): ?string
    {
        return $this->get('customer_name');
    }

    public function isDisabled(): bool
    {
        return (bool) $this->get('disabled', false);
    }
}
