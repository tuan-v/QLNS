<?php

namespace App\Http\Requests\EmployeeShiftAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Chỉ nhận Ca làm việc đang hoạt động — is_active=0 nghĩa là Ca đã
            // ngừng dùng, không gán mới được nữa (dữ liệu cũ đã gán trước đó
            // vẫn giữ nguyên, không tự hủy khi Ca bị tắt sau này).
            'work_shift_id' => ['required', Rule::exists('work_shifts', 'id')->where('is_active', 1)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            // VD: [1,2,3,4,5] = Thứ 2 - Thứ 6 (1 = Thứ 2 ... 7 = Chủ nhật,
            // đúng theo chú thích ở migration create_employee_shift_assignments_table).
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_shift_id.required' => 'Vui lòng chọn ca làm việc',
            'work_shift_id.exists' => 'Ca làm việc không tồn tại',
            'effective_from.required' => 'Ngày bắt đầu không được để trống',
            'effective_from.date' => 'Ngày bắt đầu không đúng định dạng',
            'effective_to.date' => 'Ngày kết thúc không đúng định dạng',
            'effective_to.after' => 'Ngày kết thúc phải sau ngày bắt đầu',
            'work_days.required' => 'Vui lòng chọn ít nhất 1 ngày làm việc trong tuần',
            'work_days.min' => 'Vui lòng chọn ít nhất 1 ngày làm việc trong tuần',
            'work_days.*.between' => 'Ngày trong tuần không hợp lệ',
            'work_days.*.distinct' => 'Ngày trong tuần bị chọn trùng',
        ];
    }
}
