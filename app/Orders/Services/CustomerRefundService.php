<?php

namespace App\Orders\Services;

use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Mail\RefundStatusMail;
use App\Models\Admin;
use App\Models\User;
use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\DTOs\CustomerRefundDto;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\RefundStatus;
use App\Orders\Models\CustomerRefund;
use App\Orders\Models\CustomerRefundItem;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Support\AdminPermissions;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class CustomerRefundService implements CustomerRefundServiceInterface
{
    public function __construct(
        protected CouponRedemptionInterface $couponRedemption,
    ) {}

    public function createRefund(string $sourceType, string $sourceId, string $sellerOrderId, array $units, bool $customerPaid, ?int $amountPaise = null): CustomerRefundDto
    {
        if ($sourceType === '' || $sourceId === '' || $units === []) {
            throw new DomainException('A refund needs a source and at least one item.');
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $sellerOrderId, $units, $customerPaid, $amountPaise) {
            if (CustomerRefund::where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
                throw new DomainException("A refund already exists for {$sourceType} {$sourceId}.");
            }

            /** @var SellerOrder $sellerOrder */
            $sellerOrder = SellerOrder::with('order')->findOrFail($sellerOrderId);

            // Lock the lines so concurrent refunds cannot both pass the quantity guard
            $items = OrderItem::where('seller_order_id', $sellerOrderId)
                ->whereIn('id', array_keys($units))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];
            foreach ($units as $orderItemId => $unitCount) {
                /** @var OrderItem|null $item */
                $item = $items->get($orderItemId);
                if (! $item) {
                    throw new DomainException("Order item {$orderItemId} does not belong to this order.");
                }

                $unitCount = (int) $unitCount;
                $prior = $this->refundedUnits((int) $item->id);
                if ($unitCount <= 0 || $prior + $unitCount > (int) $item->qty) {
                    throw new DomainException("Cannot refund {$unitCount} unit(s) of {$item->title}: {$prior} of {$item->qty} already refunded.");
                }

                $lines[] = [
                    'item' => $item,
                    'units' => $unitCount,
                    'prior' => $prior,
                    'amount' => UnitRefundCalculator::calculate(
                        unitPricePaise: (int) $item->unit_price,
                        lineQty: (int) $item->qty,
                        lineCouponPaise: (int) $item->coupon_discount_amount,
                        unitsPrior: $prior,
                        units: $unitCount,
                    ),
                ];
            }

            $total = array_sum(array_map(fn ($line) => $line['amount']->amountPaise, $lines));

            // An admin may refund less than the units are worth (e.g. QC failed); never more
            if ($amountPaise !== null) {
                if ($amountPaise < 1 || $amountPaise > $total) {
                    throw new DomainException('The refund must be between ₹0.01 and ₹'.number_format($total / 100, 2).'.');
                }
                $total = $amountPaise;
            }

            /** @var CustomerRefund $refund */
            $refund = CustomerRefund::create([
                'order_id' => $sellerOrder->order_id,
                'seller_order_id' => $sellerOrder->id,
                'user_id' => (int) $sellerOrder->order->user_id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                // Cash owed back: nothing when the customer was never charged (the per-unit value stays on the items)
                'amount_paise' => $customerPaid ? $total : 0,
                'status' => $customerPaid && $total > 0 ? RefundStatus::Pending : RefundStatus::NotCollected,
            ]);

            foreach ($lines as $line) {
                CustomerRefundItem::create([
                    'customer_refund_id' => $refund->id,
                    'order_item_id' => $line['item']->id,
                    'units' => $line['units'],
                    'gross_paise' => $line['amount']->grossPaise,
                    'coupon_share_paise' => $line['amount']->couponSharePaise,
                    'amount_paise' => $line['amount']->amountPaise,
                ]);

                $this->releaseCouponUnits($sellerOrder->order->order_number, $line['item'], $line['units'], $line['prior']);
            }

            return $this->toDto($refund->load('items'));
        });
    }

    public function refundedUnits(int $orderItemId): int
    {
        return (int) CustomerRefundItem::where('order_item_id', $orderItemId)->sum('units');
    }

    public function hasOpenRefunds(int $userId): bool
    {
        return CustomerRefund::where('user_id', $userId)
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value])
            ->exists();
    }

    public function customerChargedForUndeliveredUnits(string $sellerOrderId): bool
    {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::with('order')->findOrFail($sellerOrderId);

        // Exhaustive on purpose: a prepaid method must decide this explicitly
        return match ($sellerOrder->order->payment_method) {
            PaymentMethod::CashOnDelivery => false,
        };
    }

    public function amountOwedForUnits(string $sellerOrderId, array $units): int
    {
        return $this->customerChargedForUndeliveredUnits($sellerOrderId) ? 0 : $this->unitsValue($sellerOrderId, $units);
    }

    public function unitsValue(string $sellerOrderId, array $units): int
    {
        $items = OrderItem::where('seller_order_id', $sellerOrderId)->whereIn('id', array_keys($units))->get()->keyBy('id');

        $value = 0;
        foreach ($units as $orderItemId => $unitCount) {
            /** @var OrderItem|null $item */
            $item = $items->get($orderItemId);
            if (! $item) {
                throw new DomainException("Order item {$orderItemId} does not belong to this order.");
            }

            $value += UnitRefundCalculator::calculate(
                unitPricePaise: (int) $item->unit_price,
                lineQty: (int) $item->qty,
                lineCouponPaise: (int) $item->coupon_discount_amount,
                unitsPrior: $this->refundedUnits((int) $item->id),
                units: (int) $unitCount,
            )->amountPaise;
        }

        return $value;
    }

    public function updatePayoutDetails(string $refundId, int $userId, array $details): CustomerRefundDto
    {
        $validated = $this->validatePayoutDetails($details);

        return DB::transaction(function () use ($refundId, $userId, $validated) {
            /** @var CustomerRefund $refund */
            $refund = CustomerRefund::lockForUpdate()->findOrFail($refundId);

            if ($refund->user_id !== $userId) {
                throw new DomainException('This refund does not belong to you.');
            }

            if (! $refund->status->isOpen()) {
                throw new DomainException('Payout details can only be changed while the refund is pending or failed.');
            }

            $isUpi = $validated['payout_method'] === CustomerRefund::METHOD_UPI;

            $refund->update([
                'payout_method' => $validated['payout_method'],
                'upi_id' => $isUpi ? $validated['upi_id'] : null,
                'bank_account_holder' => $isUpi ? null : $validated['bank_account_holder'],
                'bank_account_number' => $isUpi ? null : $validated['bank_account_number'],
                'bank_ifsc' => $isUpi ? null : $validated['bank_ifsc'],
                // New details on a failed refund put it back in the payout queue
                'status' => RefundStatus::Pending,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return $this->toDto($refund);
        });
    }

    public function markPaid(string $refundId, string $utr, int $adminId): CustomerRefundDto
    {
        $utr = strtoupper(trim($utr));
        if (! preg_match('/^[A-Z0-9]{6,64}$/', $utr)) {
            throw new DomainException('Enter the bank or UPI reference (UTR): 6 to 64 letters or digits.');
        }

        $refund = DB::transaction(function () use ($refundId, $utr, $adminId) {
            /** @var CustomerRefund $refund */
            $refund = CustomerRefund::lockForUpdate()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::Pending) {
                throw new DomainException("Only a pending refund can be marked paid (this one is {$refund->status->label()}).");
            }

            if (! $refund->hasPayoutDetails()) {
                throw new DomainException('The customer has not provided UPI or bank details yet.');
            }

            $refund->update([
                'status' => RefundStatus::Paid,
                'utr' => $utr,
                'paid_at' => now(),
                'processed_by_admin_id' => $adminId,
            ]);

            return $refund;
        });

        $this->notify($refund);

        return $this->toDto($refund);
    }

    public function markFailed(string $refundId, string $reason, int $adminId): CustomerRefundDto
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give a reason for the failed refund.');
        }

        $refund = DB::transaction(function () use ($refundId, $reason, $adminId) {
            /** @var CustomerRefund $refund */
            $refund = CustomerRefund::lockForUpdate()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::Pending) {
                throw new DomainException("Only a pending refund can be marked failed (this one is {$refund->status->label()}).");
            }

            $refund->update([
                'status' => RefundStatus::Failed,
                'failure_reason' => $reason,
                'failed_at' => now(),
                'processed_by_admin_id' => $adminId,
            ]);

            return $refund;
        });

        $this->notify($refund);

        return $this->toDto($refund);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    protected function validatePayoutDetails(array $details): array
    {
        if (isset($details['bank_ifsc']) && is_string($details['bank_ifsc'])) {
            $details['bank_ifsc'] = strtoupper(trim($details['bank_ifsc']));
        }

        /** @var array<string, string> $validated */
        $validated = Validator::make($details, [
            'payout_method' => ['required', 'in:upi,bank'],
            'upi_id' => ['required_if:payout_method,upi', 'nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]{2,256}@[a-zA-Z][a-zA-Z0-9]{1,63}$/'],
            'bank_account_holder' => ['required_if:payout_method,bank', 'nullable', 'string', 'max:100', "regex:/^[A-Za-z][A-Za-z .'-]{1,99}$/"],
            'bank_account_number' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^\d{9,18}$/'],
            'bank_ifsc' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ], [
            'upi_id.regex' => 'Enter a valid UPI ID, like name@bank.',
            'bank_account_number.regex' => 'Account number must be 9 to 18 digits.',
            'bank_ifsc.regex' => 'Enter a valid IFSC, like HDFC0001234.',
            'bank_account_holder.regex' => 'Enter the account holder name as on the bank account.',
        ])->validate();

        return $validated;
    }

    /**
     * Release the coupon share for these units using the Coupons partial-return rule.
     */
    protected function releaseCouponUnits(string $orderNumber, OrderItem $item, int $units, int $prior): void
    {
        $lineCoupon = (int) $item->coupon_discount_amount;
        if ($lineCoupon <= 0) {
            return;
        }

        $result = $this->couponRedemption->releaseCumulativeItemUnits(
            orderReference: $orderNumber,
            lineId: $item->listing_id ?? $item->id,
            unitsReturnedNow: $units,
            unitsReturnedPrior: $prior,
            totalUnits: (int) $item->qty,
            lineAllocatedDiscount: $lineCoupon,
        );

        if (! $result->success) {
            Log::warning('Coupon release for refunded units failed', [
                'order_number' => $orderNumber,
                'order_item_id' => $item->id,
                'error' => $result->errorMessage,
            ]);
        }
    }

    /**
     * The customer hears about paid and failed refunds; refund managers only about failures (something to act on).
     * Only masked payout details go out.
     */
    protected function notify(CustomerRefund $refund): void
    {
        $refund->loadMissing('order');

        $customer = User::find($refund->user_id);
        if ($customer?->email) {
            Mail::to($customer->email)->queue(new RefundStatusMail($refund));
        }

        if ($refund->status !== RefundStatus::Failed) {
            return;
        }

        try {
            $managers = Admin::permission(AdminPermissions::REFUNDS_MANAGE)->where('is_active', true)->get();
        } catch (PermissionDoesNotExist) {
            // Permissions not synced yet (fresh install): no refund managers to notify
            $managers = collect();
        }

        $managers->each(fn (Admin $admin) => Mail::to($admin->email)->queue(new RefundStatusMail($refund, forAdmin: true)));
    }

    protected function toDto(CustomerRefund $refund): CustomerRefundDto
    {
        return new CustomerRefundDto(
            id: $refund->id,
            sellerOrderId: $refund->seller_order_id,
            sourceType: $refund->source_type,
            sourceId: $refund->source_id,
            amountPaise: $refund->amount_paise,
            status: $refund->status,
            units: $refund->items->mapWithKeys(fn (CustomerRefundItem $item) => [$item->order_item_id => $item->units])->all(),
        );
    }
}
