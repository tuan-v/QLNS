<?php

namespace App\Services\Payroll;

use InvalidArgumentException;

/**
 * Thuế TNCN từ tiền lương, tiền công — biểu lũy tiến từng phần.
 *
 * Bộ số liệu ĐI THEO KỲ TÍNH THUẾ, không phải hằng số cố định: luật đổi mốc
 * 2026 nên tính lại bảng lương của kỳ cũ bằng luật mới sẽ ra sai số. Mọi nơi
 * gọi BẮT BUỘC truyền năm của kỳ lương (tham số không có giá trị mặc định —
 * chủ ý, để không ai lỡ dùng nhầm năm hiện tại cho kỳ lương cũ).
 *
 * Từ kỳ tính thuế 2026:
 *   - Giảm trừ bản thân 15.500.000đ/tháng, người phụ thuộc 6.200.000đ/người
 *     — Nghị quyết 110/2025/UBTVQH15 (ban hành 17/10/2025, hiệu lực 01/01/2026).
 *   - Biểu lũy tiến rút từ 7 xuống 5 bậc — Luật Thuế TNCN 109/2025/QH15
 *     (ban hành 10/12/2025). Luật có hiệu lực chung 01/7/2026 NHƯNG điều khoản
 *     chuyển tiếp cho thu nhập từ tiền lương, tiền công của cá nhân cư trú
 *     áp dụng từ KỲ TÍNH THUẾ 2026 (tức 01/01/2026) — đã đối chiếu cổng thông
 *     tin Chính phủ xaydungchinhsach.chinhphu.vn.
 *
 * Đến hết kỳ tính thuế 2025: giảm trừ theo Nghị quyết 954/2020/UBTVQH14
 * (11.000.000 / 4.400.000) và biểu 7 bậc theo Luật TNCN 2007 (Thông tư
 * 111/2013/TT-BTC hướng dẫn).
 *
 * CẢNH BÁO: đây là số liệu pháp luật, phải rà lại khi Nhà nước điều chỉnh.
 * Thêm mốc mới = thêm 1 phần tử vào self::RULES, không sửa phần tử cũ (bảng
 * lương kỳ cũ phải tính lại ra đúng số đã chốt).
 */
class PersonalIncomeTaxCalculator
{
    /**
     * Khóa = kỳ tính thuế ĐẦU TIÊN áp dụng bộ số đó, xếp giảm dần khi tra.
     * 'brackets': [trần thu nhập tính thuế/tháng (null = không trần), thuế suất].
     */
    private const RULES = [
        // Nghị quyết 110/2025/UBTVQH15 + Luật 109/2025/QH15
        2026 => [
            'personal_deduction' => 15_500_000,
            'dependant_deduction' => 6_200_000,
            'brackets' => [
                [10_000_000, 0.05],
                [30_000_000, 0.10],
                [60_000_000, 0.20],
                [100_000_000, 0.30],
                [null, 0.35],
            ],
        ],
        // Nghị quyết 954/2020/UBTVQH14 + Thông tư 111/2013/TT-BTC
        0 => [
            'personal_deduction' => 11_000_000,
            'dependant_deduction' => 4_400_000,
            'brackets' => [
                [5_000_000, 0.05],
                [10_000_000, 0.10],
                [18_000_000, 0.15],
                [32_000_000, 0.20],
                [52_000_000, 0.25],
                [80_000_000, 0.30],
                [null, 0.35],
            ],
        ],
    ];

    /** Giảm trừ bản thân/tháng của kỳ tính thuế $taxYear. */
    public function personalDeduction(int $taxYear): float
    {
        return (float) $this->rulesFor($taxYear)['personal_deduction'];
    }

    /** Giảm trừ cho MỖI người phụ thuộc/tháng của kỳ tính thuế $taxYear. */
    public function dependantDeduction(int $taxYear): float
    {
        return (float) $this->rulesFor($taxYear)['dependant_deduction'];
    }

    /**
     * Thu nhập tính thuế = thu nhập sau bảo hiểm − giảm trừ bản thân − giảm
     * trừ người phụ thuộc, không âm. Tách thành hàm riêng để nơi hiển thị
     * (cột taxable_income trên phiếu lương) dùng CHUNG đúng một công thức với
     * nơi tính thuế — trước đây PayrollService tự trừ 11.000.000 bằng hằng số
     * chép tay, đổi luật ở đây mà quên chỗ kia là lệch âm thầm.
     */
    public function taxableIncome(float $grossAfterInsurance, int $taxYear, int $dependants = 0): float
    {
        $rules = $this->rulesFor($taxYear);
        $deduction = $rules['personal_deduction'] + $dependants * $rules['dependant_deduction'];

        return round(max(0, $grossAfterInsurance - $deduction), 2);
    }

    public function calculate(float $grossAfterInsurance, int $taxYear, int $dependants = 0): float
    {
        if ($dependants < 0) {
            throw new InvalidArgumentException('Số người phụ thuộc không được âm.');
        }

        $taxableIncome = $this->taxableIncome($grossAfterInsurance, $taxYear, $dependants);

        $tax = 0.0;
        $previousCeiling = 0;

        foreach ($this->rulesFor($taxYear)['brackets'] as [$ceiling, $rate]) {
            if ($taxableIncome <= $previousCeiling) {
                break;
            }

            $ceiling ??= $taxableIncome;
            $tax += (min($taxableIncome, $ceiling) - $previousCeiling) * $rate;
            $previousCeiling = $ceiling;
        }

        return round($tax, 2);
    }

    /** Bộ số liệu của kỳ tính thuế $taxYear — mốc lớn nhất mà $taxYear đã chạm tới. */
    private function rulesFor(int $taxYear): array
    {
        foreach (self::RULES as $appliesFrom => $rules) {
            if ($taxYear >= $appliesFrom) {
                return $rules;
            }
        }

        throw new InvalidArgumentException("Không có quy định thuế TNCN cho kỳ tính thuế {$taxYear}.");
    }
}
