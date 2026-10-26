<?php

namespace App\Settlements\Enums;

enum LedgerEntryType: string
{
    case Sale = 'sale';
    case Commission = 'commission';
    case ClosingFee = 'closing_fee';
    case ShippingFee = 'shipping_fee';
    case CollectionFee = 'collection_fee';
    case GstOnFees = 'gst_on_fees';
    case Tcs = 'tcs';
    case Tds = 'tds';
    case Reversal = 'reversal';
    case Payout = 'payout';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Item Sale',
            self::Commission => 'Marketplace Commission',
            self::ClosingFee => 'Fixed Closing Fee',
            self::ShippingFee => 'Shipping & Logistics Fee',
            self::CollectionFee => 'Payment Collection Fee',
            self::GstOnFees => 'GST on Marketplace Fees (18%)',
            self::Tcs => 'GST TCS (0.5%)',
            self::Tds => 'Income Tax TDS (Sec 194-O)',
            self::Reversal => 'Order Cancellation Reversal',
            self::Payout => 'Bank Payout Disbursement',
            self::Adjustment => 'Ledger Adjustment',
        };
    }

    public function isCredit(): bool
    {
        return $this === self::Sale;
    }
}
