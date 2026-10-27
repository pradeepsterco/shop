<?php

namespace App\Branding\Models;

use Illuminate\Database\Eloquent\Model;

class BrandingSetting extends Model
{
    /**
     * @var string
     */
    protected $table = 'branding_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'marketplace_name',
        'short_name',
        'seller_area_name',
        'admin_area_name',
        'support_email',
        'default_logo_light',
        'default_logo_dark',
        'storefront_logo_light',
        'storefront_logo_dark',
        'customer_logo_light',
        'customer_logo_dark',
        'seller_logo_light',
        'seller_logo_dark',
        'admin_logo_light',
        'admin_logo_dark',
        'email_logo_light',
        'email_logo_dark',
        'favicon',
    ];
}
