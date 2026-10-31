<?php

namespace App\Helpdesk\Services;

use DomainException;

/**
 * Keeps customer and seller identities out of text that staff pass between linked tickets.
 * Customers and sellers only ever deal with the marketplace.
 */
class AnonymityGuard
{
    protected const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /** Handle@bank, e.g. name@okaxis or 98765@ybl (no dot after the @). */
    protected const UPI = '/(?<![\w.\-])[\w.\-]{2,}@[a-z]{2,}(?![\w.@])/i';

    /** Indian mobile numbers with an optional +91 / 0 prefix and one space or dash in the middle. */
    protected const PHONE = '/(?<!\d)(?:\+?91[\s\-]?|0)?[6-9]\d{4}[\s\-]?\d{5}(?!\d)/';

    /**
     * Problems found in the text, as short phrases for the staff member to fix.
     *
     * @param  array<string, string|null>  $identifiers  label => value that must not appear, e.g. ['the customer\'s name' => 'Asha Verma']
     * @return list<string>
     */
    public function problems(string $text, array $identifiers = []): array
    {
        $problems = [];

        if (preg_match(self::EMAIL, $text)) {
            $problems[] = 'an email address';
        }

        $withoutEmails = (string) preg_replace(self::EMAIL, ' ', $text);
        if (preg_match(self::UPI, $withoutEmails)) {
            $problems[] = 'a UPI ID';
        }

        if (preg_match(self::PHONE, $text)) {
            $problems[] = 'a phone number';
        }

        foreach ($identifiers as $label => $value) {
            $value = trim((string) $value);
            if (mb_strlen($value) >= 3 && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                $problems[] = $label;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, string|null>  $identifiers
     */
    public function assertClean(string $text, array $identifiers = []): void
    {
        $problems = $this->problems($text, $identifiers);

        if ($problems) {
            throw new DomainException('Remove '.implode(', ', $problems).' before sending. Customers and sellers only deal with the marketplace.');
        }
    }

    /**
     * What must not cross from a customer ticket to its seller: the customer's name (and first name), email and ticket number.
     *
     * @return array<string, string|null>
     */
    public static function customerIdentifiers(string $name, string $email, string $ticketNumber): array
    {
        $firstName = strtok($name, ' ') ?: null;

        return [
            'the customer\'s name' => $name,
            'the customer\'s first name' => $firstName !== $name ? $firstName : null,
            'the customer\'s email' => $email,
            'the customer\'s ticket number' => $ticketNumber,
        ];
    }

    /**
     * What must not cross from a seller ticket to the customer: the store and business names, email and ticket number.
     *
     * @return array<string, string|null>
     */
    public static function sellerIdentifiers(string $storeName, ?string $legalName, string $email, string $ticketNumber): array
    {
        return [
            'the store name' => $storeName,
            'the seller\'s business name' => $legalName,
            'the seller\'s email' => $email,
            'the seller\'s ticket number' => $ticketNumber,
        ];
    }
}
