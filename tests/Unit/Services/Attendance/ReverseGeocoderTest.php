<?php

namespace Tests\Unit\Services\Attendance;

use App\Services\Attendance\ReverseGeocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Không bao giờ gọi mạng thật: mọi test đều Http::fake() + chặn request lạc.
// Kế thừa Tests\TestCase (boot Laravel) vì ReverseGeocoder dùng facade
// Http/Cache/Log/config().
class ReverseGeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_returns_address_and_sends_required_headers_and_params(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => '1 Lê Lợi, Quận 1, TP.HCM'])]);

        $address = (new ReverseGeocoder())->addressFor(10.7769, 106.7009);

        $this->assertSame('1 Lê Lợi, Quận 1, TP.HCM', $address);
        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/reverse')
                && $request['lat'] == 10.7769
                && $request['lon'] == 106.7009
                && $request['format'] === 'jsonv2'
                // Chính sách Nominatim: bắt buộc User-Agent nhận diện ứng dụng.
                && $request->header('User-Agent')[0] === config('services.nominatim.user_agent')
                && $request->header('Accept-Language')[0] === 'vi';
        });
    }

    public function test_second_lookup_for_nearby_coordinates_uses_cache(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Tòa nhà A'])]);
        $geocoder = new ReverseGeocoder();

        $geocoder->addressFor(10.77691, 106.70091);
        // Lệch ở chữ số thập phân thứ 5 (~1m) → cùng khóa cache (làm tròn 4 chữ số).
        $second = $geocoder->addressFor(10.77694, 106.70094);

        $this->assertSame('Tòa nhà A', $second);
        Http::assertSentCount(1);
    }

    public function test_returns_null_when_service_responds_with_error(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertNull((new ReverseGeocoder())->addressFor(10.7769, 106.7009));
    }

    public function test_returns_null_when_connection_fails(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $this->assertNull((new ReverseGeocoder())->addressFor(10.7769, 106.7009));
    }

    public function test_returns_null_when_response_has_no_address(): void
    {
        // Nominatim trả {"error":"Unable to geocode"} cho tọa độ giữa biển...
        Http::fake(['*' => Http::response(['error' => 'Unable to geocode'])]);

        $this->assertNull((new ReverseGeocoder())->addressFor(0.0, 0.0));
    }

    public function test_failed_lookup_is_not_cached(): void
    {
        Http::fakeSequence()
            ->push('boom', 500)
            ->push(['display_name' => 'Đã có địa chỉ']);
        $geocoder = new ReverseGeocoder();

        $this->assertNull($geocoder->addressFor(10.7769, 106.7009));
        $this->assertSame('Đã có địa chỉ', $geocoder->addressFor(10.7769, 106.7009));
    }
}
