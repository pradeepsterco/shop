<?php

namespace App\Helpdesk\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row helpdesk settings.
 *
 * @property int $id
 * @property string|null $grievance_name
 * @property string|null $grievance_designation
 * @property string|null $grievance_email
 * @property string|null $grievance_phone
 * @property string|null $grievance_address
 * @property string|null $grievance_hours
 */
class HelpdeskSetting extends Model
{
    protected $table = 'helpdesk_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grievance_name',
        'grievance_designation',
        'grievance_email',
        'grievance_phone',
        'grievance_address',
        'grievance_hours',
    ];
}
