<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class CustomerAddressRules
{
    public const STATES = [
        'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar',
        'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa',
        'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka',
        'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
        'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim',
        'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    ];

    /**
     * Get validation rules for customer address fields.
     *
     * @param  string  $prefix  Optional prefix for Livewire field names (e.g. 'address' generates 'addressName')
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $k = fn (string $field) => $prefix === '' ? $field : $prefix.ucfirst($field);

        return [
            $k('name') => ['required', 'string', 'max:100'],
            $k('phone') => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            $k('pincode') => ['required', 'string', 'regex:/^\d{6}$/'],
            $k('locality') => ['required', 'string', 'max:150'],
            $prefix === 'address' ? 'addressLine' : $k('address_line') => ['required', 'string', 'max:255'],
            $k('city') => ['required', 'string', 'max:100'],
            $k('state') => ['required', 'string', Rule::in(self::STATES)],
            $k('landmark') => ['nullable', 'string', 'max:150'],
            $prefix === 'address' ? 'addressAlternatePhone' : $k('alternate_phone') => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            $k('type') => ['required', Rule::in(['home', 'work'])],
        ];
    }

    /**
     * Get customized validation error messages.
     *
     * @param  string  $prefix  Optional prefix for Livewire field names
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        $k = fn (string $field) => $prefix === '' ? $field : $prefix.ucfirst($field);

        $phoneKey = $k('phone');
        $pincodeKey = $k('pincode');
        $altPhoneKey = $prefix === 'address' ? 'addressAlternatePhone' : $k('alternate_phone');

        return [
            "{$phoneKey}.regex" => 'Please enter a valid 10-digit Indian mobile number.',
            "{$pincodeKey}.regex" => 'Please enter a valid 6-digit PIN code.',
            "{$altPhoneKey}.regex" => 'Please enter a valid 10-digit mobile number.',
        ];
    }
}
