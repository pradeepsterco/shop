<?php

namespace App\Helpdesk\Services;

use App\Helpdesk\Models\HelpdeskSetting;
use App\Models\Admin;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * The grievance officer shown on the public grievance page (Consumer Protection (E-Commerce) Rules, 2020).
 */
class GrievanceOfficerService
{
    public const CACHE_KEY = 'helpdesk.grievance_officer';

    public const FIELDS = ['grievance_name', 'grievance_designation', 'grievance_email', 'grievance_phone', 'grievance_address', 'grievance_hours'];

    /**
     * @return array<string, string|null>
     */
    public function get(): array
    {
        /** @var array<string, string|null> */
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $setting = HelpdeskSetting::query()->first();

            return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => $setting?->{$field}])->all();
        });
    }

    /**
     * Name, designation and at least one contact are needed before the page counts as configured.
     */
    public function isConfigured(): bool
    {
        $officer = $this->get();

        return filled($officer['grievance_name']) && filled($officer['grievance_designation'])
            && (filled($officer['grievance_email']) || filled($officer['grievance_phone']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, Admin $admin): void
    {
        $validated = Validator::make($data, [
            'grievance_name' => ['required', 'string', 'max:120'],
            'grievance_designation' => ['required', 'string', 'max:120'],
            'grievance_email' => ['required', 'email', 'max:191'],
            'grievance_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'grievance_address' => ['required', 'string', 'max:500'],
            'grievance_hours' => ['nullable', 'string', 'max:120'],
        ], [
            'grievance_phone.regex' => 'Enter a valid phone number.',
        ])->validate();

        $setting = HelpdeskSetting::query()->firstOrNew();
        $setting->fill($validated)->save();
        Cache::forget(self::CACHE_KEY);

        AdminActivityLogger::log('support.grievance_officer_updated', $setting, [
            'name' => $validated['grievance_name'],
            'designation' => $validated['grievance_designation'],
        ], $admin);
    }
}
