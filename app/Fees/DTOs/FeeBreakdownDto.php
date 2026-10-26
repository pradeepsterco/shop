<?php

namespace App\Fees\DTOs;

use App\Shared\Enums\ShippingZone;
use App\Shared\ValueObjects\Money;

final readonly class FeeBreakdownDto
{
    public function __construct(
        public Money $sellingPrice,
        public int $qty,
        public Money $lineTotal,
        public Money $taxableValue,
        public Money $commission,
        public Money $closingFee,
        public Money $shippingFee,
        public Money $collectionFee,
        public Money $totalMarketplaceFees,
        public Money $gstOnFees,
        public Money $tcs,
        public Money $tds,
        public Money $totalDeductions,
        public Money $netPayout,
        public Money $claimableCredits,
        public ShippingZone $zone,
        public int $scheduleId,
        public int $scheduleVersion,
        public int $commissionRateBps,
        public float $gstRateOnFees,
        public float $tcsRate,
        public float $tdsRate,
        public ?int $orderItemId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'selling_price_paise' => $this->sellingPrice->getPaise(),
            'qty' => $this->qty,
            'line_total_paise' => $this->lineTotal->getPaise(),
            'taxable_value_paise' => $this->taxableValue->getPaise(),
            'commission_paise' => $this->commission->getPaise(),
            'closing_fee_paise' => $this->closingFee->getPaise(),
            'shipping_fee_paise' => $this->shippingFee->getPaise(),
            'collection_fee_paise' => $this->collectionFee->getPaise(),
            'total_marketplace_fees_paise' => $this->totalMarketplaceFees->getPaise(),
            'gst_on_fees_paise' => $this->gstOnFees->getPaise(),
            'tcs_paise' => $this->tcs->getPaise(),
            'tds_paise' => $this->tds->getPaise(),
            'total_deductions_paise' => $this->totalDeductions->getPaise(),
            'net_payout_paise' => $this->netPayout->getPaise(),
            'claimable_credits_paise' => $this->claimableCredits->getPaise(),
            'zone' => $this->zone->value,
            'schedule_id' => $this->scheduleId,
            'schedule_version' => $this->scheduleVersion,
            'commission_rate_bps' => $this->commissionRateBps,
            'gst_rate_on_fees' => $this->gstRateOnFees,
            'tcs_rate' => $this->tcsRate,
            'tds_rate' => $this->tdsRate,
            'order_item_id' => $this->orderItemId,
        ];
    }
}
