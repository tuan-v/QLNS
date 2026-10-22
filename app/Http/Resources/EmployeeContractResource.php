<?php

namespace App\Http\Resources;

use App\Services\DepartmentScopeService;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeContractResource extends JsonResource
{
    public function toArray($request)
    {
        // Lương hợp đồng: chỉ người có quyền xem lương toàn công ty hoặc chính chủ (2026-10-22 — Trưởng phòng không xem lương).
        $hideSalary = ! app(DepartmentScopeService::class)->canViewSalary($request->user(), $this->employee_id);

        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'contract_type' => $this->contract_type,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'signed_at' => $this->signed_at,
            'agreed_salary' => $hideSalary ? null : $this->agreed_salary,
            'insurance_salary' => $hideSalary ? null : $this->insurance_salary,
            'salary_change_note' => $hideSalary ? null : $this->salary_change_note,
            'status' => $this->status,
            'terminated_at' => $this->terminated_at,
            // Giao diện cần biết hợp đồng đã có tệp PDF chưa: để ẩn nút Xem
            // trước/Tải file (không có file thì bấm vào chỉ ra lỗi) và để hiện
            // nút "Bổ sung". KHÔNG trả đường dẫn thật trong storage ra ngoài.
            'has_file' => $this->contract_file_path !== null,
            // route('...') sinh URL trỏ tới action download ở Bước D — chưa cần lo tên route sai vì Bước D mới đăng ký
            'download_url' => route('employees.contracts.download', [
                'employee' => $this->employee_id,
                'contract' => $this->id,
            ]),
        ];
    }
}
