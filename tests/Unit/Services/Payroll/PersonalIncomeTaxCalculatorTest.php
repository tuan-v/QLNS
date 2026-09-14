<?php

namespace Tests\Unit\Services\Payroll;

use App\Services\Payroll\PersonalIncomeTaxCalculator;
use PHPUnit\Framework\TestCase;

// Class thuần (không Eloquent, không DB) nên kế thừa PHPUnit\Framework\TestCase
// trần được, giống LeaveRequestServiceCalculationTest — khác
// AttendanceServiceCalculationTest (phải kế thừa Tests\TestCase vì đụng
// Eloquent cast datetime).
class PersonalIncomeTaxCalculatorTest extends TestCase
{
    private function calculator(): PersonalIncomeTaxCalculator
    {
        return new PersonalIncomeTaxCalculator();
    }

    public function test_income_at_or_below_personal_deduction_pays_no_tax(): void
    {
        // Giảm trừ bản thân 11.000.000 — thu nhập sau bảo hiểm bằng đúng mức
        // này thì thu nhập tính thuế = 0.
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(11_000_000), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(5_000_000), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->calculator()->calculate(0), 0.001);
    }

    public function test_taxable_income_exactly_at_first_bracket_ceiling(): void
    {
        // Thu nhập tính thuế = 5.000.000 (đúng trần bậc 1) -> chỉ chịu 5%.
        $tax = $this->calculator()->calculate(16_000_000); // 16tr - 11tr giảm trừ = 5tr

        $this->assertEqualsWithDelta(250_000.0, $tax, 0.001);
    }

    public function test_taxable_income_spanning_three_brackets(): void
    {
        // Ví dụ kinh điển: thu nhập tính thuế 12.000.000 -> 1.050.000 tiền thuế
        // (5tr*5% + 5tr*10% + 2tr*15%).
        $tax = $this->calculator()->calculate(23_000_000); // 23tr - 11tr = 12tr

        $this->assertEqualsWithDelta(1_050_000.0, $tax, 0.001);
    }

    public function test_taxable_income_exactly_at_bracket_boundary(): void
    {
        // Thu nhập tính thuế = 18.000.000 (đúng trần bậc 3).
        $tax = $this->calculator()->calculate(29_000_000); // 29tr - 11tr = 18tr

        // 5tr*5% + 5tr*10% + 8tr*15% = 250.000 + 500.000 + 1.200.000
        $this->assertEqualsWithDelta(1_950_000.0, $tax, 0.001);
    }

    public function test_taxable_income_reaching_top_bracket(): void
    {
        // Thu nhập tính thuế 100.000.000 -> vượt cả 6 bậc đầu, phần dư 20tr
        // chịu mức 35% cao nhất.
        $tax = $this->calculator()->calculate(111_000_000); // 111tr - 11tr = 100tr

        // 5tr*5% + 5tr*10% + 8tr*15% + 14tr*20% + 20tr*25% + 28tr*30% + 20tr*35%
        // = 250.000 + 500.000 + 1.200.000 + 2.800.000 + 5.000.000 + 8.400.000 + 7.000.000
        $this->assertEqualsWithDelta(25_150_000.0, $tax, 0.001);
    }

    public function test_negative_income_after_insurance_does_not_produce_negative_tax(): void
    {
        // Lương sau bảo hiểm thấp hơn cả giảm trừ bản thân (vd nghỉ không
        // lương gần hết tháng) -> thuế phải là 0, không được ra số âm.
        $tax = $this->calculator()->calculate(-5_000_000);

        $this->assertEqualsWithDelta(0.0, $tax, 0.001);
    }
}
