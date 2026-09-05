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
            // route('...') sinh URL trỏ tới action download ở Bước D — chưa cần lo tên route sai vì Bước D mới đăng ký
            'download_url' => route('employees.contracts.download', [
                'employee' => $this->employee_id,
                'contract' => $this->id,
            ]),
        ];
    }
}
