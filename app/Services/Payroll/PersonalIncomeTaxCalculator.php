<?php

namespace App\Services\Payroll;

// CẢNH BÁO: các mốc bậc thuế và mức giảm trừ bản thân dưới đây là quy định
// PHÁP LUẬT thuế TNCN Việt Nam (biểu lũy tiến từng phần, Thông tư 111/2013 +
// Nghị quyết 954/2020 cho giảm trừ gia cảnh) — có thể đã bị Nhà nước điều
// chỉnh sau thời điểm này. PHẢI kiểm tra lại số liệu hiện hành trước khi
// dùng cho dữ liệu thật, đây chỉ là số dùng để dự án chạy được.
// Giảm trừ NGƯỜI PHỤ THUỘC (4.4tr/người) CHƯA áp dụng vì Employee/Contract
// chưa có trường lưu số người phụ thuộc — chỉ trừ giảm trừ bản thân.
class PersonalIncomeTaxCalculator
{
    private const PERSONAL_DEDUCTION = 11_000_000;

    private const BRACKETS = [
        ['up_to' => 5_000_000, 'rate' => 0.05],
        ['up_to' => 10_000_000, 'rate' => 0.10],
        ['up_to' => 18_000_000, 'rate' => 0.15],
        ['up_to' => 32_000_000, 'rate' => 0.20],
        ['up_to' => 52_000_000, 'rate' => 0.25],
        ['up_to' => 80_000_000, 'rate' => 0.30],
        ['up_to' => null, 'rate' => 0.35],
    ];

    public function calculate(float $grossAfterInsurance): float
    {
        $taxableIncome = max(0, $grossAfterInsurance - self::PERSONAL_DEDUCTION);

        $tax = 0.0;
        $previousCeiling = 0;

        foreach (self::BRACKETS as $bracket) {
            if ($taxableIncome <= $previousCeiling) {
                break;
            }

            $ceiling = $bracket['up_to'] ?? $taxableIncome;
            $amountInBracket = min($taxableIncome, $ceiling) - $previousCeiling;
            $tax += $amountInBracket * $bracket['rate'];
            $previousCeiling = $ceiling;
        }

        return round($tax, 2);
    }
}
