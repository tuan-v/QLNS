<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\HolidayAnnouncement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Thưởng lễ cộng vào bảng lương (ngoài tài liệu gốc, người dùng duyệt 2026-10-12): lấy từ
// các thông báo nghỉ lễ ĐÃ PHÁT HÀNH có thưởng, chọn chi cùng lương của đúng kỳ đang tính.
// Mức thưởng theo loại hợp đồng hiệu lực vào ngày đầu kỳ nghỉ (chính thức / thử việc / thực
// tập); vào làm sau ngày đó thì không được thưởng dịp này. Dòng thưởng không gắn nhóm
// (category null) chỉ để hiển thị trên văn bản, không tự cộng cho ai.
class HolidayBonusCalculator
{
    /** @return Collection<int, HolidayAnnouncement> */
    public function announcementsForPeriod(int $month, int $year): Collection
    {
        return HolidayAnnouncement::where('status', HolidayAnnouncement::STATUS_PUBLISHED)
            ->where('has_bonus', true)
            ->where('bonus_payroll_month', $month)
            ->where('bonus_payroll_year', $year)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, HolidayAnnouncement>  $announcements
     * @return array<int, array{announcement_id: int, name: string, category: string, amount: float}>
     */
    public function bonusesFor(Employee $employee, Collection $announcements): array
    {
        $bonuses = [];

        foreach ($announcements as $announcement) {
            $startDate = $announcement->schedule_snapshot['start_date'] ?? null;
            if (! $startDate) {
                continue;
            }
            $date = Carbon::parse($startDate);

            if ($employee->hire_date && Carbon::parse($employee->hire_date)->gt($date)) {
                continue;
            }

            $contract = $employee->contracts()
                ->where('start_date', '<=', $date->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString()))
                ->latest('start_date')
                ->first();
            $category = $contract ? array_search($contract->contract_type, HolidayAnnouncement::CATEGORY_CONTRACT_TYPES, true) : false;
            if ($category === false) {
                continue;
            }

            $row = collect($announcement->bonus_rows ?? [])->firstWhere('category', $category);
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $bonuses[] = [
                'announcement_id' => $announcement->id,
                'name' => (string) ($announcement->schedule_snapshot['name'] ?? ''),
                'category' => $category,
                'amount' => $amount,
            ];
        }

        return $bonuses;
    }
}
