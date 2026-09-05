<?php

namespace App\Http\Resources;

use App\Repositories\EmployeeRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeResource extends JsonResource
{
    // Cache tập ID cấp trên theo từng viewer, dùng chung cho mọi EmployeeResource
    // được tạo trong cùng 1 request (vd: mỗi dòng của index()). Không cache thì
    // mỗi dòng trong danh sách sẽ tự đi duyệt lại toàn bộ chuỗi quản lý của CÙNG
    // MỘT người xem — tốn N lần query giống hệt nhau cho N dòng, dù kết quả không đổi.
    private static array $ancestorIdsCache = [];

    private static function ancestorIdsFor(?\App\Models\Employee $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        if (! array_key_exists($viewer->id, self::$ancestorIdsCache)) {
            self::$ancestorIdsCache[$viewer->id] = app(EmployeeRepository::class)->ancestorIds($viewer);
        }

        return self::$ancestorIdsCache[$viewer->id];
    }

    public function toArray($request)
    {
        // show()/store()/update() không đi qua EmployeeRepository::find() nên không có
        // sẵn with(...) — loadMissing() chỉ query nếu quan hệ CHƯA được nạp, nên với
        // index() (đã with() từ trước) dòng này không tốn thêm query nào.
        $this->resource->loadMissing(['department', 'position', 'manager']);

        $viewer = $request->user()?->employee;
        $hideSensitive = in_array($this->resource->id, self::ancestorIdsFor($viewer), true);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
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
