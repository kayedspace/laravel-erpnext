<?php

namespace Kayedspace\Erpnext\Documents;

use Kayedspace\Erpnext\Documents\Concerns\Submittable;

class PaymentEntry extends Document
{
    use Submittable;

    public static function doctype(): string
    {
        return 'Payment Entry';
    }

    public function paidAmount(): float
    {
        return $this->float('paid_amount');
    }

    public function party(): ?string
    {
        return $this->get('party');
    }

    /**
     * The invoices this payment is allocated against.
     *
     * @return array<int, array<string, mixed>>
     */
    public function references(): array
    {
        $references = $this->get('references', []);

        return is_array($references) ? $references : [];
    }
}
