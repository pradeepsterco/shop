<?php

namespace App\Orders\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $seller_profile_id
 * @property string $financial_year
 * @property int $last_sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerInvoiceSequence extends Model
{
    protected $table = 'seller_invoice_sequences';

    protected $fillable = [
        'seller_profile_id',
        'financial_year',
        'last_sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seller_profile_id' => 'integer',
            'last_sequence' => 'integer',
        ];
    }
}
