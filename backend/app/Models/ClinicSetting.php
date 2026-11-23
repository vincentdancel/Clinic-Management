<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicSetting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    /**
     * Default clinic settings keyed by setting name.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'clinic_name' => 'Clinic Care Medical Center',
            'clinic_tagline' => 'Primary Care & Outpatient Clinic',
            'clinic_email' => 'contact@cliniccare.local',
            'clinic_phone' => '+63 2 8123 4567',
            'clinic_address' => '123 Health Ave., Makati City',
            'clinic_website' => '',
            'tax_id' => '',
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'appointment_duration_minutes' => 30,
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
            'allow_walk_ins' => true,
            'currency_code' => 'PHP',
            'tax_rate_percent' => 0.0,
            'invoice_prefix' => 'INV-',
            'low_stock_threshold' => 10,
            'appointment_reminders_enabled' => true,
            'low_stock_alerts_enabled' => true,
        ];
    }

    /**
     * Return all clinic settings merged with defaults and typed for the API.
     *
     * @return array<string, mixed>
     */
    public static function allFormatted(): array
    {
        $defaults = static::defaults();
        $stored = static::query()->pluck('value', 'key')->all();

        $result = [];
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $stored)) {
                $result[$key] = $default;
                continue;
            }

            $raw = $stored[$key];

            if (is_bool($default)) {
                $result[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($default)) {
                $result[$key] = (int) $raw;
            } elseif (is_float($default)) {
                $result[$key] = (float) $raw;
            } elseif (is_array($default)) {
                $decoded = json_decode((string) $raw, true);
                $result[$key] = is_array($decoded) ? $decoded : $default;
            } else {
                $result[$key] = (string) ($raw ?? '');
            }
        }

        return $result;
    }
}
