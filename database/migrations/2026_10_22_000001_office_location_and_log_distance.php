<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Khoảng cách THẬT từ nơi bấm chấm công tới công ty (yêu cầu 2026-10-22). Chỉ để xem — chấm ở đâu cũng được,
// không chặn, không gắn cờ (khác "Điểm chấm công" đã bỏ ở 2026_09_30_000001).
//  - system_settings: cài đặt chung dạng khóa → giá trị; trước mắt chỉ có tọa độ công ty.
//  - attendance_logs.distance_meters: chụp lại khoảng cách lúc chấm, đổi tọa độ công ty sau này không đổi số cũ.
return new class () extends Migration {
    // Tọa độ công ty người dùng gửi 2026-10-22: 21°02'28.5"N 105°43'55.9"E.
    private const OFFICE_LATITUDE = 21.0412500;

    private const OFFICE_LONGITUDE = 105.7321944;

    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('distance_meters', 12, 1)->nullable()->after('accuracy_meters');
        });

        DB::table('system_settings')->insert([
            ['key' => 'office_latitude', 'value' => (string) self::OFFICE_LATITUDE, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'office_longitude', 'value' => (string) self::OFFICE_LONGITUDE, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Tính bù cho các lượt chấm cũ có tọa độ (cùng công thức với OfficeLocation::haversineMeters()).
        DB::table('attendance_logs')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->chunkById(500, function ($logs) {
                foreach ($logs as $log) {
                    DB::table('attendance_logs')->where('id', $log->id)->update([
                        'distance_meters' => round($this->haversine((float) $log->latitude, (float) $log->longitude), 1),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn('distance_meters');
        });
        Schema::dropIfExists('system_settings');
    }

    private function haversine(float $lat, float $lng): float
    {
        $dLat = deg2rad($lat - self::OFFICE_LATITUDE);
        $dLng = deg2rad($lng - self::OFFICE_LONGITUDE);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad(self::OFFICE_LATITUDE)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
};
