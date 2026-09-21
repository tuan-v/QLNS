<?php

namespace Tests\Unit\Services\Attendance;

use App\Services\Attendance\DeviceInfoParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Chuỗi User-Agent dưới đây là mẫu thật của từng loại trình duyệt/thiết bị —
// parser chỉ dùng regex thuần, không cần boot Laravel nên kế thừa thẳng
// PHPUnit\Framework\TestCase.
class DeviceInfoParserTest extends TestCase
{
    public static function userAgents(): array
    {
        return [
            'iPhone Safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
                'iPhone (iOS 17.2) · Safari',
            ],
            'iPhone Chrome (CriOS)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
                'iPhone (iOS 16.6) · Chrome',
            ],
            'iPad Safari' => [
                'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'iPad (iOS 17.1) · Safari',
            ],
            'Samsung có mã máy' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'Android SM-S911B (Android 13) · Chrome',
            ],
            'Android cũ có Build/' => [
                'Mozilla/5.0 (Linux; Android 9; Redmi Note 7 Build/PKQ1.180904.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.116 Mobile Safari/537.36',
                'Android Redmi Note 7 (Android 9) · Chrome',
            ],
            'Android bị Chrome che mã máy thành K' => [
                'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'Điện thoại Android 10 · Chrome',
            ],
            'Máy tính bảng Android (không có Mobile)' => [
                'Mozilla/5.0 (Linux; Android 12; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Máy tính bảng Android 12 · Chrome',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
                'Android SM-S911B (Android 13) · Samsung Internet',
            ],
            'Windows Chrome' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Máy tính Windows · Chrome',
            ],
            'Windows Edge (không nhầm thành Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'Máy tính Windows · Edge',
            ],
            'Windows Firefox' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Máy tính Windows · Firefox',
            ],
            'Mac Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
                'Máy tính Mac · Safari',
            ],
            'Linux Firefox' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Máy tính Linux · Firefox',
            ],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_describe_reads_common_user_agents(string $userAgent, string $expected): void
    {
        $this->assertSame($expected, (new DeviceInfoParser())->describe($userAgent));
    }

    public function test_describe_handles_missing_user_agent(): void
    {
        $parser = new DeviceInfoParser();

        $this->assertSame('Không xác định', $parser->describe(null));
        $this->assertSame('Không xác định', $parser->describe('   '));
    }

    public function test_describe_falls_back_for_unknown_client(): void
    {
        // Công cụ dòng lệnh (curl...) không thuộc loại thiết bị/trình duyệt nào.
        $this->assertSame('Thiết bị không xác định', (new DeviceInfoParser())->describe('curl/8.4.0'));
    }
}
