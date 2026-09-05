<?php

namespace App\Http\Resources;

use App\Repositories\EmployeeRepository;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray($request)
    {
        // show()/store()/update() không đi qua EmployeeRepository::find() nên không có
        // sẵn with(...) — loadMissing() chỉ query nếu quan hệ CHƯA được nạp, nên với
        // index() (đã with() từ trước) dòng này không tốn thêm query nào.
        $this->resource->loadMissing(['department', 'position', 'manager']);

        $viewer = $request->user()?->employee;
        $hideSensitive = app(EmployeeRepository::class)->isSuperiorOf($this->resource, $viewer);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'full_name' => $this->full_name,
            'company_email' => $this->company_email,
            'employment_status' => $this->employment_status,
            'hire_date' => $this->hire_date,
            'department' => $this->whenLoaded('department'),
            'position' => $this->whenLoaded('position'),
            'manager' => $this->whenLoaded('manager', fn () => new EmployeeResource($this->manager)),

            // Field nhạy cảm — ẩn nếu người xem là cấp dưới của nhân viên này
            'date_of_birth' => $hideSensitive ? null : $this->date_of_birth,
            'gender' => $hideSensitive ? null : $this->gender,
            'phone' => $hideSensitive ? null : $this->phone,
            'personal_email' => $hideSensitive ? null : $this->personal_email,
            'cccd' => $hideSensitive ? null : $this->cccd,
            'personal_tax_code' => $hideSensitive ? null : $this->personal_tax_code,
            'addresses' => $hideSensitive ? null : $this->addresses,
        ];
    }
}
