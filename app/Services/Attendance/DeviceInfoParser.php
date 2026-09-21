<?php

namespace App\Services\Attendance;

// Đọc chuỗi User-Agent của trình duyệt thành tên thiết bị dễ đọc để HR nhìn
// vào biết nhân viên chấm công bằng máy gì (2026-09-21, theo yêu cầu người
// dùng: "dùng điện thoại thì ra tên thiết bị điện thoại"). Chỉ tự viết bằng
// regex, không cài thêm thư viện.
//
// GIỚI HẠN CỐ HỮU của trình duyệt (không phải lỗi code): web không được phép
// biết tên thương mại đầy đủ của máy — iPhone chỉ báo "iPhone" + bản iOS (không
// phân biệt đời máy), Android thường có mã máy ("SM-S911B" = Galaxy S23) nhưng
// Chrome mới đã che thành "K" (xem isUsableAndroidModel()), khi đó chỉ còn
// "Android 13". Vì vậy kết quả có dạng "iPhone (iOS 17.2) · Safari" chứ không
// phải "iPhone 15 Pro". Chuỗi User-Agent gốc vẫn được lưu riêng để đối chiếu.
class DeviceInfoParser
{
    public function describe(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Không xác định';
        }

        $device = $this->detectDevice($userAgent);
        $browser = $this->detectBrowser($userAgent);

        return $browser ? $device.' · '.$browser : $device;
    }

    // Thứ tự KIỂM TRA quan trọng: UA của iPhone/iPad có chứa cả "Mac OS X",
    // UA của Android có chứa cả "Linux" — phải nhận diện loại cụ thể trước
    // khi rơi xuống Mac/Linux chung chung.
    private function detectDevice(string $ua): string
    {
        if (preg_match('/iPhone/i', $ua)) {
            return 'iPhone'.$this->iosVersionSuffix($ua);
        }

        if (preg_match('/iPad/i', $ua)) {
            return 'iPad'.$this->iosVersionSuffix($ua);
        }

        if (preg_match('/Android\s+([\d.]+)/i', $ua, $android)) {
            $version = $android[1];
            $model = $this->androidModel($ua);

            if ($model !== null) {
                return "Android {$model} (Android {$version})";
            }

            // Không có "Mobile" trong UA = máy tính bảng (quy ước của Chrome).
            $kind = preg_match('/Mobile/i', $ua) ? 'Điện thoại' : 'Máy tính bảng';

            return "{$kind} Android {$version}";
        }

        if (preg_match('/Windows NT/i', $ua)) {
            return 'Máy tính Windows';
        }

        if (preg_match('/CrOS/i', $ua)) {
            return 'Chromebook';
        }

        if (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            return 'Máy tính Mac';
        }

        if (preg_match('/Linux/i', $ua)) {
            return 'Máy tính Linux';
        }

        return 'Thiết bị không xác định';
    }

    // "CPU iPhone OS 17_2 like Mac OS X" -> " (iOS 17.2)"
    private function iosVersionSuffix(string $ua): string
    {
        if (preg_match('/OS\s+(\d+)[_.](\d+)/i', $ua, $m)) {
            return " (iOS {$m[1]}.{$m[2]})";
        }

        return '';
    }

    // "Android 13; SM-S911B) ..." -> "SM-S911B". Bản cũ còn thêm " Build/TP1A..."
    // phía sau mã máy — cắt bỏ.
    private function androidModel(string $ua): ?string
    {
        if (! preg_match('/Android\s+[\d.]+;\s*([^;)]+)/i', $ua, $m)) {
            return null;
        }

        $model = trim((string) preg_replace('/\s*Build\/.*$/i', '', $m[1]));

        return $this->isUsableAndroidModel($model) ? $model : null;
    }

    // Chrome bản mới cố tình che mã máy thành "K" (chống theo dõi), "wv" là
    // đánh dấu WebView — cả 2 không phải tên máy thật.
    private function isUsableAndroidModel(string $model): bool
    {
        return strlen($model) >= 2 && ! in_array(strtolower($model), ['wv', 'mobile', 'linux'], true);
    }

    // Thứ tự KIỂM TRA quan trọng: Edge/Opera/Samsung Internet đều ghi thêm cả
    // "Chrome/..." và "Safari/..." trong UA, Chrome lại ghi thêm "Safari/..."
    // — phải kiểm loại đặc thù trước rồi mới tới Chrome, cuối cùng mới Safari.
    private function detectBrowser(string $ua): ?string
    {
        return match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/i', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/i', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            default => null,
        };
    }
}
