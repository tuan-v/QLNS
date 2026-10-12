<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTransfer;
use App\Models\PayrollDetail;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use App\Models\ResignationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Tab "Hành trình" (ngoài tài liệu gốc — người dùng duyệt): gom từ dữ liệu SẴN CÓ, không
// lưu bảng riêng nên luôn khớp nguồn:
//  - Tuyển dụng: ứng viên có hired_employee_id = nhân viên -> CV, duyệt, phỏng vấn (phản
//    hồi, điểm phiếu chấm), offer, nhận việc.
//  - Hợp đồng & lương: mỗi hợp đồng là 1 giai đoạn lương, so với hợp đồng trước -> tăng/giảm
//    bao nhiêu (số tiền + %); chấm dứt/hết hạn. Luân chuyển (phòng ban/chức vụ). Nghỉ việc.
//  - Lương thực nhận: phiếu lương từng kỳ (payroll_details).
// Quyền: employee.journey (Admin + HR).
class EmployeeJourneyService
{
    private const CONTRACT_LABELS = ['thu_viec' => 'Thử việc', 'chinh_thuc' => 'Chính thức', 'thuc_tap' => 'Thực tập'];

    public function build(Employee $employee): array
    {
        $employee->loadMissing('department:id,name', 'position:id,name');

        $contracts = EmployeeContract::where('employee_id', $employee->id)->orderBy('start_date')->orderBy('id')->get();
        $salaryHistory = $this->salaryHistory($contracts);
        $candidate = RecruitmentCandidate::withTrashed()
            ->with(['opening:id,title', 'interviews.scorecard', 'interviews.confirmer:id,user_name', 'offers', 'reviewer:id,user_name'])
            ->where('hired_employee_id', $employee->id)
            ->latest('id')
            ->first();

        $events = collect()
            ->merge($this->recruitmentEvents($candidate, $employee))
            ->merge($this->contractEvents($contracts, $salaryHistory))
            ->merge($this->transferEvents($employee))
            ->merge($this->resignationEvents($employee))
            ->sortByDesc(fn (array $event) => $event['date'].sprintf('%03d', $event['order'] ?? 0))
            ->values()
            ->map(fn (array $event) => collect($event)->except('order')->all());

        return [
            'summary' => $this->summary($employee, $salaryHistory, $candidate),
            'salary_history' => $salaryHistory,
            'payslips' => $this->payslips($employee),
            'events' => $events,
        ];
    }

    private function salaryHistory(Collection $contracts): array
    {
        $previous = null;

        return $contracts->map(function (EmployeeContract $contract) use (&$previous) {
            $salary = (float) $contract->agreed_salary;
            $change = $previous === null ? null : $salary - $previous;
            $row = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'contract_type' => $contract->contract_type,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'status' => $contract->status,
                'salary' => $salary,
                'change_amount' => $change,
                'change_percent' => $previous ? round($change * 100 / $previous, 2) : null,
            ];
            $previous = $salary;

            return $row;
        })->values()->all();
    }

    private function summary(Employee $employee, array $salaryHistory, ?RecruitmentCandidate $candidate): array
    {
        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;
        $endDate = $employee->termination_date ? Carbon::parse($employee->termination_date) : today();
        $tenure = $hireDate && $hireDate->lte($endDate) ? $hireDate->diff($endDate) : null;

        // Lương hiện tại = hợp đồng đang hiệu lực; không có thì hợp đồng gần nhất.
        $current = collect($salaryHistory)->firstWhere('status', 'active') ?? collect($salaryHistory)->last();
        $starting = $salaryHistory[0]['salary'] ?? null;
        $currentSalary = $current['salary'] ?? null;

        $scorecard = $candidate?->interviews->pluck('scorecard')->filter()->firstWhere('status', 'submitted');
        $acceptedOffer = $candidate?->offers->firstWhere('status', RecruitmentOffer::STATUS_ACCEPTED);

        return [
            'hire_date' => $hireDate?->toDateString(),
            'termination_date' => $employee->termination_date ? Carbon::parse($employee->termination_date)->toDateString() : null,
            'tenure' => $tenure ? ['years' => $tenure->y, 'months' => $tenure->m, 'days' => $tenure->d] : null,
            'employment_status' => $employee->employment_status,
            'department' => $employee->department?->name,
            'position' => $employee->position?->name,
            'starting_salary' => $starting,
            'current_salary' => $currentSalary,
            'total_change_amount' => $starting !== null && $currentSalary !== null ? $currentSalary - $starting : null,
            'total_change_percent' => $starting ? round(($currentSalary - $starting) * 100 / $starting, 2) : null,
            'contracts_count' => count($salaryHistory),
            'recruitment' => $candidate ? [
                'opening' => $candidate->opening?->title,
                'applied_at' => $candidate->created_at?->toDateString(),
                'score_percent' => $scorecard ? (float) $scorecard->score_percent : null,
                'offer_salary' => $acceptedOffer ? (float) $acceptedOffer->salary : null,
            ] : null,
        ];
    }

    private function payslips(Employee $employee): array
    {
        return PayrollDetail::with('payroll:id,period_month,period_year,status')
            ->where('employee_id', $employee->id)
            ->get()
            ->filter(fn ($detail) => $detail->payroll)
            ->sortBy(fn ($detail) => sprintf('%04d%02d', $detail->payroll->period_year, $detail->payroll->period_month))
            ->map(fn ($detail) => [
                'period' => sprintf('%02d/%04d', $detail->payroll->period_month, $detail->payroll->period_year),
                'period_month' => (int) $detail->payroll->period_month,
                'period_year' => (int) $detail->payroll->period_year,
                'payroll_status' => $detail->payroll->status,
                'base_salary' => (float) $detail->base_salary,
                'gross_salary' => (float) $detail->gross_salary,
                'net_salary' => (float) $detail->net_salary,
                'standard_work_days' => (float) $detail->standard_work_days,
                'actual_work_days' => (float) $detail->actual_work_days,
                'overtime_amount' => (float) $detail->overtime_amount,
            ])
            ->values()
            ->all();
    }

    private function recruitmentEvents(?RecruitmentCandidate $candidate, Employee $employee): array
    {
        if (! $candidate) {
            return [];
        }

        $opening = $candidate->opening?->title ?? 'đợt tuyển';
        $events = [$this->event($candidate->created_at, 'recruitment', 'cv_submitted', 'Nộp CV', "Ứng tuyển vào \"{$opening}\".", 1)];

        if ($candidate->reviewed_at) {
            $events[] = $this->event($candidate->reviewed_at, 'recruitment', 'cv_approved', 'CV được duyệt', $candidate->reviewer ? "Duyệt bởi {$candidate->reviewer->user_name}." : null, 2);
        }

        foreach ($candidate->interviews as $interview) {
            $parts = [($interview->format === RecruitmentInterview::FORMAT_ONLINE ? 'Trực tuyến' : 'Trực tiếp')];
            if ($interview->confirmation_status === RecruitmentInterview::CONFIRMATION_CONFIRMED) {
                $parts[] = 'ứng viên đồng ý tham gia';
            }
            if ($interview->scorecard?->status === 'submitted') {
                $parts[] = "điểm phiếu chấm {$interview->scorecard->score_percent}% (đạt từ {$interview->scorecard->pass_percent}%)";
            }
            $result = match ($interview->result) {
                'passed' => 'Phỏng vấn: Đạt',
                'failed' => 'Phỏng vấn: Không đạt',
                default => 'Phỏng vấn',
            };
            $events[] = $this->event($interview->scheduled_at, 'recruitment', 'interview', $result, ucfirst(implode(', ', $parts)).'.', 3);
        }

        foreach ($candidate->offers as $offer) {
            $label = self::CONTRACT_LABELS[$offer->contract_type] ?? $offer->contract_type;
            if ($offer->sent_at) {
                $events[] = $this->event($offer->sent_at, 'recruitment', 'offer_sent', 'Gửi thư mời nhận việc', "{$label}, lương đề xuất ".$this->money($offer->salary).', bắt đầu '.$offer->start_date->format('d/m/Y').'.', 4, ['salary' => (float) $offer->salary]);
            }
            if ($offer->responded_at) {
                $accepted = $offer->status === RecruitmentOffer::STATUS_ACCEPTED;
                $events[] = $this->event($offer->responded_at, 'recruitment', $accepted ? 'offer_accepted' : 'offer_declined', $accepted ? 'Chấp nhận thư mời' : 'Từ chối thư mời', $offer->response_note, 5);
            }
        }

        $events[] = $this->event($employee->hire_date, 'recruitment', 'hired', 'Nhận việc', "Trở thành nhân viên {$employee->code}.", 6);

        return $events;
    }

    private function contractEvents(Collection $contracts, array $salaryHistory): array
    {
        $byId = collect($salaryHistory)->keyBy('contract_id');
        $events = [];

        foreach ($contracts as $contract) {
            $row = $byId->get($contract->id);
            $label = self::CONTRACT_LABELS[$contract->contract_type] ?? $contract->contract_type;
            $description = 'Lương '.$this->money($contract->agreed_salary);
            if ($row['change_amount'] !== null) {
                $direction = $row['change_amount'] > 0 ? 'tăng' : ($row['change_amount'] < 0 ? 'giảm' : 'giữ nguyên');
                $description .= $row['change_amount'] == 0
                    ? ' (giữ nguyên so với hợp đồng trước)'
                    : " ({$direction} ".$this->money(abs($row['change_amount'])).", {$row['change_percent']}% so với hợp đồng trước)";
            }
            $description .= $contract->end_date ? ', đến '.$contract->end_date->format('d/m/Y').'.' : ', không xác định thời hạn.';

            $events[] = $this->event($contract->start_date, 'contract', 'contract_started', "Hợp đồng {$label} ({$contract->contract_number})", $description, 7, [
                'salary' => (float) $contract->agreed_salary,
                'change_amount' => $row['change_amount'],
                'change_percent' => $row['change_percent'],
                'contract_status' => $contract->status,
            ]);

            if ($contract->status === 'terminated' && $contract->terminated_at) {
                $events[] = $this->event($contract->terminated_at, 'contract', 'contract_terminated', "Chấm dứt hợp đồng {$contract->contract_number}", null, 8);
            }
        }

        return $events;
    }

    private function transferEvents(Employee $employee): array
    {
        return EmployeeTransfer::with('fromDepartment:id,name', 'toDepartment:id,name', 'oldPosition:id,name', 'newPosition:id,name')
            ->where('employee_id', $employee->id)
            ->get()
            ->map(function (EmployeeTransfer $transfer) {
                [$title, $type] = match ($transfer->type) {
                    EmployeeTransfer::TYPE_ONBOARD => ['Tiếp nhận vào phòng ban', 'onboard'],
                    EmployeeTransfer::TYPE_TRANSFER => ['Điều chuyển phòng ban', 'transfer'],
                    default => ['Thay đổi phòng ban/chức vụ', 'adjustment'],
                };
                $parts = [];
                if ($transfer->type === EmployeeTransfer::TYPE_ONBOARD) {
                    $parts[] = trim(($transfer->toDepartment?->name ?? '').($transfer->newPosition ? ' · '.$transfer->newPosition->name : ''), ' ·');
                } else {
                    if ($transfer->from_department_id !== $transfer->to_department_id) {
                        $parts[] = ($transfer->fromDepartment?->name ?? '—').' → '.($transfer->toDepartment?->name ?? '—');
                    }
                    if ($transfer->old_position_id !== $transfer->new_position_id) {
                        $parts[] = 'Chức vụ: '.($transfer->oldPosition?->name ?? '—').' → '.($transfer->newPosition?->name ?? '—');
                    }
                }
                if ($transfer->reason) {
                    $parts[] = $transfer->reason;
                }

                return $this->event($transfer->effective_date, 'position', $type, $title, implode('. ', array_filter($parts)).'.', 9);
            })
            ->all();
    }

    private function resignationEvents(Employee $employee): array
    {
        $events = [];

        foreach (ResignationRequest::where('employee_id', $employee->id)->orderBy('id')->get() as $request) {
            $events[] = $this->event($request->created_at, 'resignation', 'resignation_submitted', 'Nộp đơn nghỉ việc', 'Ngày làm việc cuối dự kiến '.$request->last_working_date->format('d/m/Y').'.', 10);

            $outcome = match ($request->status) {
                ResignationRequest::STATUS_APPROVED => 'Đơn nghỉ việc được duyệt',
                ResignationRequest::STATUS_REJECTED => 'Đơn nghỉ việc bị từ chối',
                ResignationRequest::STATUS_CANCELLED => 'Rút đơn nghỉ việc',
                default => null,
            };
            if ($outcome && ($request->decided_at || $request->status === ResignationRequest::STATUS_CANCELLED)) {
                $events[] = $this->event($request->decided_at ?? $request->updated_at, 'resignation', 'resignation_'.$request->status, $outcome, $request->decision_note, 11);
            }
            if ($request->applied_at) {
                $events[] = $this->event($request->last_working_date, 'resignation', 'resigned', 'Nghỉ việc', 'Ngày làm việc cuối.', 12);
            }
        }

        return $events;
    }

    private function event($date, string $group, string $type, string $title, ?string $description, int $order, array $meta = []): array
    {
        $at = $date instanceof Carbon ? $date : Carbon::parse($date);

        return [
            'date' => $at->toIso8601String(),
            'group' => $group,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'meta' => $meta ?: null,
            'order' => $order,
        ];
    }

    private function money($value): string
    {
        return number_format((float) $value, 0, ',', '.').' ₫';
    }
}
