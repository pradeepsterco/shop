<?php

namespace App\Sellers\Services;

use App\Models\SellerProfile;
use App\Sellers\Contracts\SellerPayoutLookupInterface;
use App\Sellers\DTOs\SellerPayoutDetailsDto;

class SellerPayoutLookupService implements SellerPayoutLookupInterface
{
    public function getSellerPayoutDetails(int $sellerProfileId): ?SellerPayoutDetailsDto
    {
        /** @var SellerProfile|null $profile */
        $profile = SellerProfile::with('user')->find($sellerProfileId);

        if (! $profile) {
            // Anonymized or deleted seller profile
            return new SellerPayoutDetailsDto(
                sellerProfileId: $sellerProfileId,
                storeName: 'Deleted / Anonymized Seller',
                sellerName: 'Deleted Seller',
                bankAccountHolder: '—',
                maskedAccountNumber: '—',
                ifsc: '—',
                pan: null,
                status: 'anonymized',
                isBankDetailsVerified: false,
                bankFingerprint: '',
                isAnonymized: true,
            );
        }

        $accountNumber = (string) $profile->bank_account_number;
        $last4 = strlen($accountNumber) >= 4 ? substr($accountNumber, -4) : $accountNumber;
        $maskedAccount = $last4 ? '•••• •••• '.$last4 : '—';
        $fingerprint = $this->computeFingerprint($accountNumber, $last4);

        // Bank details verified if never updated post-review or updated before/on reviewed_at
        $isVerified = $profile->bank_updated_at === null
            || ($profile->reviewed_at !== null && $profile->bank_updated_at <= $profile->reviewed_at);

        $isAnonymized = $profile->isAnonymized();

        return new SellerPayoutDetailsDto(
            sellerProfileId: $profile->id,
            storeName: (string) $profile->store_name,
            sellerName: (string) ($profile->user->name ?? $profile->store_name),
            bankAccountHolder: (string) $profile->bank_account_holder,
            maskedAccountNumber: $maskedAccount,
            ifsc: (string) $profile->ifsc,
            pan: $profile->pan,
            status: $profile->status->value,
            isBankDetailsVerified: $isVerified,
            bankFingerprint: $fingerprint,
            isAnonymized: $isAnonymized,
        );
    }

    public function getFullAccountNumberForExport(int $sellerProfileId): ?string
    {
        $profile = SellerProfile::find($sellerProfileId);

        return $profile ? (string) $profile->bank_account_number : null;
    }

    public function getSellerBankFingerprint(int $sellerProfileId): ?string
    {
        $profile = SellerProfile::find($sellerProfileId);
        if (! $profile) {
            return null;
        }

        $accountNumber = (string) $profile->bank_account_number;
        $last4 = strlen($accountNumber) >= 4 ? substr($accountNumber, -4) : $accountNumber;

        return $this->computeFingerprint($accountNumber, $last4);
    }

    private function computeFingerprint(string $accountNumber, string $last4): string
    {
        if ($accountNumber === '') {
            return '';
        }

        return hash('sha256', $accountNumber).':'.$last4;
    }
}
