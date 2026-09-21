<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Đổi tọa độ GPS lúc chấm công thành ĐỊA CHỈ CHỮ (2026-09-21, theo yêu cầu
// người dùng: hiển thị "địa chỉ tôi bấm chấm công") qua dịch vụ Nominatim của
// OpenStreetMap — miễn phí, không cần API key, nhưng:
//   - Chính sách của họ bắt buộc gửi User-Agent nhận diện ứng dụng và không
//     quá ~1 request/giây (quy mô chấm công nội bộ thoải mái dưới mức đó).
//   - Đây là dịch vụ NGOÀI: tọa độ của nhân viên được gửi sang máy chủ
//     OpenStreetMap (chỉ tọa độ, không kèm tên/mã nhân viên).
//
// FAIL-SOFT có chủ đích: lỗi mạng/timeout/dịch vụ ngoài sập thì trả null —
// việc chấm công KHÔNG được thất bại chỉ vì không tra ra địa chỉ (log vẫn giữ
// tọa độ thô để đối chiếu).
class ReverseGeocoder
{
    // Timeout ngắn: hàm này chạy TRONG request chấm công của nhân viên, chờ
    // lâu sẽ làm nút bấm treo. Tối đa chờ ~3 giây rồi bỏ qua.
    private const TIMEOUT_SECONDS = 3;

    private const CONNECT_TIMEOUT_SECONDS = 2;

    private const CACHE_DAYS = 1;

    public function addressFor(float $latitude, float $longitude): ?string
    {
        // Làm tròn 4 chữ số thập phân (~11m) — nhiều người chấm công cùng 1
        // tòa nhà dùng chung 1 kết quả đã tra, đỡ gọi dịch vụ ngoài lặp lại.
        $cacheKey = sprintf('reverse-geocode:%.4f,%.4f', $latitude, $longitude);

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => (string) config('services.nominatim.user_agent'),
                    'Accept-Language' => 'vi',
                ])
                ->get((string) config('services.nominatim.url'), [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                ]);
        } catch (Throwable $e) {
            Log::warning('Không tra được địa chỉ từ tọa độ chấm công: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Dịch vụ tra địa chỉ trả về HTTP '.$response->status());

            return null;
        }

        $address = $response->json('display_name');

        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        $address = mb_substr($address, 0, 500);
        Cache::put($cacheKey, $address, now()->addDays(self::CACHE_DAYS));

        return $address;
    }
}
