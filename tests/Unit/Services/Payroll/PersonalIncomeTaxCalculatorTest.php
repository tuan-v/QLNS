<?php

namespace Tests\Unit\Services\Payroll;

use App\Services\Payroll\PersonalIncomeTaxCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// Class thuần (không Eloquent, không DB) nên kế thừa PHPUnit\Framework\TestCase
// trần được, giống LeaveRequestServiceCalculationTest — khác
// AttendanceServiceCalculationTest (phải kế thừa Tests\TestCase vì đụng
// Eloquent cast datetime).
//
// Bộ số liệu đi theo KỲ TÍNH THUẾ nên mọi test đều nêu rõ năm: nhóm 2025 giữ
// nguyên để chứng minh bảng lương kỳ cũ vẫn tính ra đúng số đã chốt, nhóm 2026
// kiểm luật hiện hành (NQ 110/2025/UBTVQH15 + Luật 109/2025/QH15).
class PersonalIncomeTaxCalculatorTest extends TestCase
{
    private function calculator(): PersonalIncomeTaxCalculator
    {
        return new PersonalIncomeTaxCalculator();
    }

    /* ---------------- Kỳ tính thuế ≤ 2025: giảm trừ 11tr, biểu 7 bậc --------------- */

    public function test_2025_income_at_or_below_personal_deduction_pays_no_tax(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(11_000_000, 2025), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(5_000_000, 2025), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(0, 2025), 0.001);
    }

    public function test_2025_taxable_income_exactly_at_first_bracket_ceiling(): void
    {
        // Thu nhập tính thuế = 5.000.000 (đúng trần bậc 1) -> chỉ chịu 5%.
        $this->assertEqualsWithDelta(250_000.0, $this->calculator()->calculate(16_000_000, 2025), 0.001);
    }

    public function test_2025_taxable_income_spanning_three_brackets(): void
    {
        // Thu nhập tính thuế 12.000.000 = 5tr*5% + 5tr*10% + 2tr*15%.
        $this->assertEqualsWithDelta(1_050_000.0, $this->calculator()->calculate(23_000_000, 2025), 0.001);
    }

    public function test_2025_taxable_income_exactly_at_bracket_boundary(): void
    {
        // Thu nhập tính thuế = 18.000.000 (đúng trần bậc 3).
        $this->assertEqualsWithDelta(1_950_000.0, $this->calculator()->calculate(29_000_000, 2025), 0.001);
    }

    public function test_2025_taxable_income_reaching_top_bracket(): void
    {
        // Thu nhập tính thuế 100.000.000 -> vượt cả 6 bậc đầu, phần dư 20tr chịu 35%.
        $this->assertEqualsWithDelta(25_150_000.0, $this->calculator()->calculate(111_000_000, 2025), 0.001);
    }

    public function test_2025_negative_income_after_insurance_does_not_produce_negative_tax(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(-5_000_000, 2025), 0.001);
    }

    /* ------- Kỳ tính thuế từ 2026: giảm trừ 15,5tr + 6,2tr/NPT, biểu 5 bậc ------- */

    public function test_2026_deductions_follow_resolution_110_2025(): void
    {
        $this->assertEqualsWithDelta(15_500_000.0, $this->calculator()->personalDeduction(2026), 0.001);
        $this->assertEqualsWithDelta(6_200_000.0, $this->calculator()->dependantDeduction(2026), 0.001);
    }

    public function test_2026_income_up_to_the_personal_deduction_pays_no_tax(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(15_500_000, 2026), 0.001);
        // Mức này ở luật cũ đã phải nộp thuế, luật mới thì chưa.
        $this->assertGreaterThan(0.0, $this->calculator()->calculate(15_500_000, 2025));
    }

    public function test_2026_first_bracket_is_five_percent_up_to_ten_million(): void
    {
        // Thu nhập tính thuế = 10.000.000 (đúng trần bậc 1 mới) -> 5%.
        $this->assertEqualsWithDelta(500_000.0, $this->calculator()->calculate(25_500_000, 2026), 0.001);
    }

    public function test_2026_taxable_income_spanning_two_brackets(): void
    {
        // Thu nhập sau BH 30tr -> tính thuế 14,5tr = 10tr*5% + 4,5tr*10%.
        $this->assertEqualsWithDelta(950_000.0, $this->calculator()->calculate(30_000_000, 2026), 0.001);
    }

    public function test_2026_taxable_income_reaching_top_bracket(): void
    {
        // Thu nhập tính thuế 120tr: 10tr*5% + 20tr*10% + 30tr*20% + 40tr*30% + 20tr*35%
        // = 500.000 + 2.000.000 + 6.000.000 + 12.000.000 + 7.000.000
        $this->assertEqualsWithDelta(27_500_000.0, $this->calculator()->calculate(135_500_000, 2026), 0.001);
    }

    public function test_2026_dependant_deduction_reduces_the_tax(): void
    {
        // Thu nhập sau BH 30tr, 1 người phụ thuộc -> tính thuế 30 - 15,5 - 6,2 = 8,3tr
        // -> toàn bộ nằm bậc 1 (5%).
        $this->assertEqualsWithDelta(415_000.0, $this->calculator()->calculate(30_000_000, 2026, dependants: 1), 0.001);
        // Đủ người phụ thuộc thì về 0, không âm.
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(30_000_000, 2026, dependants: 3), 0.001);
    }

    public function test_taxable_income_helper_matches_the_deduction_of_each_period(): void
    {
        // Cùng thu nhập, khác kỳ -> khác thu nhập tính thuế. Đây chính là chỗ
        // PayrollService dùng cho cột taxable_income nên phải khớp luật từng kỳ.
        $this->assertEqualsWithDelta(9_000_000.0, $this->calculator()->taxableIncome(20_000_000, 2025), 0.001);
        $this->assertEqualsWithDelta(4_500_000.0, $this->calculator()->taxableIncome(20_000_000, 2026), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->calculator()->taxableIncome(20_000_000, 2026, dependants: 1), 0.001);
    }

    public function test_year_after_2026_keeps_using_the_newest_rules(): void
    {
        $this->assertEqualsWithDelta(
            $this->calculator()->calculate(30_000_000, 2026),
            $this->calculator()->calculate(30_000_000, 2030),
            0.001,
        );
    }

    public function test_negative_dependants_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate(30_000_000, 2026, dependants: -1);
    }
}
