<?php

namespace Kayedspace\Erpnext\Documents;

use Kayedspace\Erpnext\Documents\Concerns\Submittable;

class SalesInvoice extends Document
{
    use Submittable;

    public static function doctype(): string
    {
        return 'Sales Invoice';
    }

    public function customer(): ?string
    {
        return $this->get('customer');
    }

    public function grandTotal(): float
    {
        return $this->float('grand_total');
    }

    /**
     * What is still unpaid. Falls back to the grand total, because ERPNext omits the
     * field entirely on a draft that has never been submitted.
     */
    public function outstandingAmount(): float
    {
        return $this->float('outstanding_amount', $this->grandTotal());
    }

    public function isPaid(): bool
    {
        return $this->isSubmitted() && $this->outstandingAmount() <= 0.0;
    }
}
