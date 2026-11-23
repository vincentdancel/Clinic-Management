<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => ClinicSetting::allFormatted(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clinic_name' => ['required', 'string', 'max:255'],
            'clinic_tagline' => ['nullable', 'string', 'max:255'],
            'clinic_email' => ['nullable', 'email', 'max:255'],
            'clinic_phone' => ['nullable', 'string', 'max:50'],
            'clinic_address' => ['nullable', 'string', 'max:500'],
            'clinic_website' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'],
            'appointment_duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'allow_walk_ins' => ['required', 'boolean'],
            'currency_code' => ['required', 'string', 'size:3'],
            'tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'invoice_prefix' => ['required', 'string', 'max:20'],
            'low_stock_threshold' => ['required', 'integer', 'min:1', 'max:10000'],
            'appointment_reminders_enabled' => ['required', 'boolean'],
            'low_stock_alerts_enabled' => ['required', 'boolean'],
        ]);

        $groups = [
            'clinic_name' => 'clinic',
            'clinic_tagline' => 'clinic',
            'clinic_email' => 'clinic',
            'clinic_phone' => 'clinic',
            'clinic_address' => 'clinic',
            'clinic_website' => 'clinic',
            'tax_id' => 'clinic',
            'opening_time' => 'operations',
            'closing_time' => 'operations',
            'appointment_duration_minutes' => 'operations',
            'working_days' => 'operations',
            'allow_walk_ins' => 'operations',
            'currency_code' => 'billing',
            'tax_rate_percent' => 'billing',
            'invoice_prefix' => 'billing',
            'low_stock_threshold' => 'inventory',
            'appointment_reminders_enabled' => 'notifications',
            'low_stock_alerts_enabled' => 'notifications',
        ];

        foreach ($validated as $key => $value) {
            if (is_bool($value)) {
                $storedValue = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $storedValue = json_encode(array_values(array_unique($value)));
            } else {
                $storedValue = (string) ($value ?? '');
            }

            ClinicSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $storedValue,
                    'group' => $groups[$key] ?? 'general',
                ]
            );
        }

        return response()->json([
            'message' => 'Clinic settings updated.',
            'settings' => ClinicSetting::allFormatted(),
        ]);
    }
}
