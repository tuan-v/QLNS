<?php

namespace App\Services\Attendance;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Tọa độ công ty + khoảng cách THẬT từ nơi bấm chấm công tới công ty (yêu cầu 2026-10-22). Chỉ để xem:
// chấm công ở đâu cũng được, không chặn, không gắn cờ. Khác `accuracy_meters` (độ chính xác do trình duyệt
// tự báo) — distance_meters do server tự tính bằng công thức Haversine.
class OfficeLocation
{
    private const CACHE_KEY = 'system-settings:office-location';

    private const EARTH_RADIUS_METERS = 6371000;

    /** @return array{latitude: float, longitude: float, updated_at: ?string, updated_by: ?string}|null */
    public function get(): ?array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = SystemSetting::with('updater:id,user_name')
                ->whereIn('key', ['office_latitude', 'office_longitude'])
                ->get()
                ->keyBy('key');
            $lat = $rows->get('office_latitude');
            $lng = $rows->get('office_longitude');
            if (! is_numeric($lat?->value) || ! is_numeric($lng?->value)) {
                return null;
            }
            $latest = $lat->updated_at?->gt($lng->updated_at) ? $lat : $lng;

            return [
                'latitude' => (float) $lat->value,
                'longitude' => (float) $lng->value,
                'updated_at' => $latest->updated_at?->toIso8601String(),
                'updated_by' => $latest->updater?->user_name,
            ];
        });
    }

    public function set(float $latitude, float $longitude, User $actor): array
    {
        DB::transaction(function () use ($latitude, $longitude, $actor) {
            foreach (['office_latitude' => $latitude, 'office_longitude' => $longitude] as $key => $value) {
                SystemSetting::updateOrCreate(['key' => $key], ['value' => (string) round($value, 7), 'updated_by' => $actor->id]);
            }
        });
        Cache::forget(self::CACHE_KEY);
        return $this->get();
    }

    /** Khoảng cách (mét, 1 chữ số lẻ) từ tọa độ này tới công ty; null nếu chưa cài tọa độ công ty. */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        $office = $this->get();
        return $office === null
            ? null
            : round(self::haversineMeters($office['latitude'], $office['longitude'], $latitude, $longitude), 1);
    }

    /** Khoảng cách theo đường tròn lớn trên mặt cầu Trái Đất (bán kính trung bình 6.371 km). */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
