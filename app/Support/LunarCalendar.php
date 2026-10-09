<?php

namespace App\Support;

use Carbon\Carbon;

// Chuyển đổi Âm lịch Việt Nam <-> Dương lịch, tự viết (không dùng thư viện ngoài).
//
// Thuật toán của Hồ Ngọc Đức ("Âm lịch Việt Nam", dựa trên Jean Meeus –
// "Astronomical Algorithms"): tính ngày Sóc (trăng mới) và vị trí kinh độ Mặt
// Trời theo giờ Việt Nam (UTC+7) để xác định tháng 11 âm lịch (tháng chứa Đông
// chí) và tháng nhuận (tháng không chứa Trung khí). Đúng cho các năm 1800–2199.
//
// Toàn bộ là hàm thuần (không đụng DB), dùng cho HolidayService để tính các ngày
// lễ theo âm lịch (Tết Nguyên Đán, Giỗ Tổ Hùng Vương...).
final class LunarCalendar
{
    private const TIME_ZONE = 7.0;

    private const CAN = ['Giáp', 'Ất', 'Bính', 'Đinh', 'Mậu', 'Kỷ', 'Canh', 'Tân', 'Nhâm', 'Quý'];

    private const CHI = ['Tý', 'Sửu', 'Dần', 'Mão', 'Thìn', 'Tỵ', 'Ngọ', 'Mùi', 'Thân', 'Dậu', 'Tuất', 'Hợi'];

    /**
     * Âm lịch -> Dương lịch. Trả null nếu ngày âm không tồn tại (vd chọn tháng
     * nhuận cho năm không nhuận tháng đó).
     */
    public static function toSolar(int $lunarDay, int $lunarMonth, int $lunarYear, bool $isLeapMonth = false): ?Carbon
    {
        if ($lunarMonth < 11) {
            $a11 = self::lunarMonth11($lunarYear - 1);
            $b11 = self::lunarMonth11($lunarYear);
        } else {
            $a11 = self::lunarMonth11($lunarYear);
            $b11 = self::lunarMonth11($lunarYear + 1);
        }

        $k = self::int(0.5 + ($a11 - 2415021.076998695) / 29.530588853);
        $offset = $lunarMonth - 11;
        if ($offset < 0) {
            $offset += 12;
        }

        if ($b11 - $a11 > 365) {
            $leapOffset = self::leapMonthOffset($a11);
            $leapMonth = $leapOffset - 2;
            if ($leapMonth < 0) {
                $leapMonth += 12;
            }

            if ($isLeapMonth && $lunarMonth !== $leapMonth) {
                return null;
            }

            if ($isLeapMonth || $offset >= $leapOffset) {
                $offset += 1;
            }
        } elseif ($isLeapMonth) {
            return null;
        }

        $monthStart = self::newMoonDay($k + $offset);

        return self::fromJulianDay($monthStart + $lunarDay - 1);
    }

    /**
     * Dương lịch -> Âm lịch.
     *
     * @return array{day: int, month: int, year: int, leap: bool}
     */
    public static function fromSolar(Carbon $date): array
    {
        $dayNumber = self::julianDay((int) $date->day, (int) $date->month, (int) $date->year);
        $k = self::int(($dayNumber - 2415021.076998695) / 29.530588853);
        $monthStart = self::newMoonDay($k + 1);
        if ($monthStart > $dayNumber) {
            $monthStart = self::newMoonDay($k);
        }

        $year = (int) $date->year;
        $a11 = self::lunarMonth11($year);
        $b11 = $a11;
        if ($a11 >= $monthStart) {
            $lunarYear = $year;
            $a11 = self::lunarMonth11($year - 1);
        } else {
            $lunarYear = $year + 1;
            $b11 = self::lunarMonth11($year + 1);
        }

        $lunarDay = $dayNumber - $monthStart + 1;
        $diff = self::int(($monthStart - $a11) / 29);
        $leap = false;
        $lunarMonth = $diff + 11;

        if ($b11 - $a11 > 365) {
            $leapMonthDiff = self::leapMonthOffset($a11);
            if ($diff >= $leapMonthDiff) {
                $lunarMonth = $diff + 10;
                $leap = $diff === $leapMonthDiff;
            }
        }

        if ($lunarMonth > 12) {
            $lunarMonth -= 12;
        }
        if ($lunarMonth >= 11 && $diff < 4) {
            $lunarYear -= 1;
        }

        return ['day' => $lunarDay, 'month' => $lunarMonth, 'year' => $lunarYear, 'leap' => $leap];
    }

    // Tên năm âm lịch theo Can Chi, vd 2026 -> "Bính Ngọ".
    public static function canChiYear(int $lunarYear): string
    {
        return self::CAN[($lunarYear + 6) % 10].' '.self::CHI[($lunarYear + 8) % 12];
    }

    // Nhãn hiển thị, vd "Mùng 1 tháng 1 (Bính Ngọ)", "15 tháng 2 nhuận (Quý Mão)".
    public static function label(Carbon $date): string
    {
        $lunar = self::fromSolar($date);
        $day = $lunar['day'] <= 10 ? "Mùng {$lunar['day']}" : (string) $lunar['day'];
        $month = $lunar['month'].($lunar['leap'] ? ' nhuận' : '');

        return "{$day} tháng {$month} (".self::canChiYear($lunar['year']).')';
    }

    /* ------------------------------ Thuật toán ------------------------------ */

    private static function int(float $value): int
    {
        return (int) floor($value);
    }

    private static function julianDay(int $day, int $month, int $year): int
    {
        $a = self::int((14 - $month) / 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        $jd = $day + self::int((153 * $m + 2) / 5) + 365 * $y + self::int($y / 4) - self::int($y / 100) + self::int($y / 400) - 32045;

        if ($jd < 2299161) {
            $jd = $day + self::int((153 * $m + 2) / 5) + 365 * $y + self::int($y / 4) - 32083;
        }

        return $jd;
    }

    private static function fromJulianDay(int $jd): Carbon
    {
        if ($jd > 2299160) {
            $a = $jd + 32044;
            $b = self::int((4 * $a + 3) / 146097);
            $c = $a - self::int(($b * 146097) / 4);
        } else {
            $b = 0;
            $c = $jd + 32082;
        }

        $d = self::int((4 * $c + 3) / 1461);
        $e = $c - self::int((1461 * $d) / 4);
        $m = self::int((5 * $e + 2) / 153);
        $day = $e - self::int((153 * $m + 2) / 5) + 1;
        $month = $m + 3 - 12 * self::int($m / 10);
        $year = $b * 100 + $d - 4800 + self::int($m / 10);

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    // Thời điểm Sóc thứ k (tính từ Sóc 1/1/1900), dạng ngày Julius có phần lẻ.
    private static function newMoon(int $k): float
    {
        $t = $k / 1236.85;
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $dr = M_PI / 180;

        $jd1 = 2415020.75933 + 29.53058868 * $k + 0.0001178 * $t2 - 0.000000155 * $t3;
        $jd1 += 0.00033 * sin((166.56 + 132.87 * $t - 0.009173 * $t2) * $dr);
        $m = 359.2242 + 29.10535608 * $k - 0.0000333 * $t2 - 0.00000347 * $t3;
        $mpr = 306.0253 + 385.81691806 * $k + 0.0107306 * $t2 + 0.00001236 * $t3;
        $f = 21.2964 + 390.67050646 * $k - 0.0016528 * $t2 - 0.00000239 * $t3;

        $c1 = (0.1734 - 0.000393 * $t) * sin($m * $dr) + 0.0021 * sin(2 * $dr * $m);
        $c1 = $c1 - 0.4068 * sin($mpr * $dr) + 0.0161 * sin($dr * 2 * $mpr);
        $c1 -= 0.0004 * sin($dr * 3 * $mpr);
        $c1 = $c1 + 0.0104 * sin($dr * 2 * $f) - 0.0051 * sin($dr * ($m + $mpr));
        $c1 = $c1 - 0.0074 * sin($dr * ($m - $mpr)) + 0.0004 * sin($dr * (2 * $f + $m));
        $c1 = $c1 - 0.0004 * sin($dr * (2 * $f - $m)) - 0.0006 * sin($dr * (2 * $f + $mpr));
        $c1 = $c1 + 0.0010 * sin($dr * (2 * $f - $mpr)) + 0.0005 * sin($dr * (2 * $mpr + $m));

        $deltaT = $t < -11
            ? 0.001 + 0.000839 * $t + 0.0002261 * $t2 - 0.00000845 * $t3 - 0.000000081 * $t * $t3
            : -0.000278 + 0.000265 * $t + 0.000262 * $t2;

        return $jd1 + $c1 - $deltaT;
    }

    private static function newMoonDay(int $k): int
    {
        return self::int(self::newMoon($k) + 0.5 + self::TIME_ZONE / 24);
    }

    // Kinh độ Mặt Trời (radian) tại thời điểm jdn.
    private static function sunLongitude(float $jdn): float
    {
        $t = ($jdn - 2451545.0) / 36525;
        $t2 = $t * $t;
        $dr = M_PI / 180;
        $m = 357.52910 + 35999.05030 * $t - 0.0001559 * $t2 - 0.00000048 * $t * $t2;
        $l0 = 280.46645 + 36000.76983 * $t + 0.0003032 * $t2;
        $dl = (1.914600 - 0.004817 * $t - 0.000014 * $t2) * sin($dr * $m);
        $dl = $dl + (0.019993 - 0.000101 * $t) * sin($dr * 2 * $m) + 0.000290 * sin($dr * 3 * $m);
        $l = ($l0 + $dl) * $dr;

        return $l - M_PI * 2 * self::int($l / (M_PI * 2));
    }

    // Cung hoàng đạo (0..11) của Mặt Trời vào đầu ngày dayNumber, giờ Việt Nam.
    private static function sunLongitudeSegment(int $dayNumber): int
    {
        return self::int(self::sunLongitude($dayNumber - 0.5 - self::TIME_ZONE / 24) / M_PI * 6);
    }

    // Ngày bắt đầu tháng 11 âm lịch (tháng chứa Đông chí) của năm dương $year.
    private static function lunarMonth11(int $year): int
    {
        $offset = self::julianDay(31, 12, $year) - 2415021;
        $k = self::int($offset / 29.530588853);
        $newMoon = self::newMoonDay($k);

        if (self::sunLongitudeSegment($newMoon) >= 9) {
            $newMoon = self::newMoonDay($k - 1);
        }

        return $newMoon;
    }

    // Vị trí tháng nhuận (tính từ tháng 11 âm lịch trước đó).
    private static function leapMonthOffset(int $a11): int
    {
        $k = self::int(($a11 - 2415021.076998695) / 29.530588853 + 0.5);
        $i = 1;
        $arc = self::sunLongitudeSegment(self::newMoonDay($k + $i));

        do {
            $last = $arc;
            $i++;
            $arc = self::sunLongitudeSegment(self::newMoonDay($k + $i));
        } while ($arc !== $last && $i < 14);

        return $i - 1;
    }
}
