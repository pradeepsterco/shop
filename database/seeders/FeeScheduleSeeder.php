<?php

namespace Database\Seeders;

use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeeScheduleSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('fee_schedules')->count() > 0) {
            return;
        }

        $now = now();
        $scheduleId = DB::table('fee_schedules')->insertGetId([
            'name' => 'Standard Fee Schedule',
            'version' => 1,
            'status' => FeeScheduleStatus::Active->value,
            'effective_from' => now()->subMonths(6),
            'effective_to' => null,
            'gst_rate' => 18.00,
            'tcs_rate' => 0.50,
            'tds_standard_rate' => 0.10,
            'tds_no_pan_rate' => 5.00,
            'default_weight_grams' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Default Commission Slabs (Fallback)
        DB::table('fee_commission_slabs')->insert([
            [
                'fee_schedule_id' => $scheduleId,
                'vertical_id' => null,
                'min_price' => 0,
                'max_price' => 50000, // Up to ₹500
                'rate_bps' => 1200,   // 12.00%
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'vertical_id' => null,
                'min_price' => 50001,
                'max_price' => 100000, // ₹500.01 to ₹1,000
                'rate_bps' => 1000,    // 10.00%
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'vertical_id' => null,
                'min_price' => 100001,
                'max_price' => null,  // Above ₹1,000
                'rate_bps' => 800,     // 8.00%
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Closing Fee Slabs
        DB::table('fee_closing_slabs')->insert([
            [
                'fee_schedule_id' => $scheduleId,
                'min_price' => 0,
                'max_price' => 25000, // Up to ₹250
                'fee_amount' => 1000, // ₹10.00
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_price' => 25001,
                'max_price' => 50000, // ₹250.01 to ₹500
                'fee_amount' => 1500, // ₹15.00
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_price' => 50001,
                'max_price' => 100000, // ₹500.01 to ₹1,000
                'fee_amount' => 2000,  // ₹20.00
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_price' => 100001,
                'max_price' => null,   // Above ₹1,000
                'fee_amount' => 3000,  // ₹30.00
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Shipping Fee Slabs
        DB::table('fee_shipping_slabs')->insert([
            // Local
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 0,
                'max_weight_grams' => 500,
                'zone' => ShippingZone::Local->value,
                'fee_amount' => 3000, // ₹30.00
                'is_default' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 501,
                'max_weight_grams' => 1000,
                'zone' => ShippingZone::Local->value,
                'fee_amount' => 4500, // ₹45.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 1001,
                'max_weight_grams' => null,
                'zone' => ShippingZone::Local->value,
                'fee_amount' => 6000, // ₹60.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Regional
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 0,
                'max_weight_grams' => 500,
                'zone' => ShippingZone::Regional->value,
                'fee_amount' => 4000, // ₹40.00
                'is_default' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 501,
                'max_weight_grams' => 1000,
                'zone' => ShippingZone::Regional->value,
                'fee_amount' => 6000, // ₹60.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 1001,
                'max_weight_grams' => null,
                'zone' => ShippingZone::Regional->value,
                'fee_amount' => 8000, // ₹80.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // National
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 0,
                'max_weight_grams' => 500,
                'zone' => ShippingZone::National->value,
                'fee_amount' => 5000, // ₹50.00
                'is_default' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 501,
                'max_weight_grams' => 1000,
                'zone' => ShippingZone::National->value,
                'fee_amount' => 7500, // ₹75.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'fee_schedule_id' => $scheduleId,
                'min_weight_grams' => 1001,
                'max_weight_grams' => null,
                'zone' => ShippingZone::National->value,
                'fee_amount' => 10000, // ₹100.00
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Collection Rules
        DB::table('fee_collection_rules')->insert([
            'fee_schedule_id' => $scheduleId,
            'payment_method' => 'cod',
            'rate_bps' => 0,
            'fee_amount' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
