<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeContractResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'contract_type' => $this->contract_type,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'signed_at' => $this->signed_at,
            'agreed_salary' => $this->agreed_salary,
            'insurance_salary' => $this->insurance_salary,
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
