<?php

namespace App\Orders\DTOs;

use DateTimeInterface;

final readonly class SellerOrderInvoiceDto
{
    public function __construct(
        public string $sellerOrderId,
        public int $sellerProfileId,
        public string $invoiceNumber,
        public DateTimeInterface $invoiceDate,
        public string $financialYear,
        public int $sequenceNumber,
    ) {}
}
