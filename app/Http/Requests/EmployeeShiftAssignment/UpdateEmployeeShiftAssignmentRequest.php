<?php

namespace App\Http\Requests\EmployeeShiftAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Chỉ nhận Ca làm việc đang hoạt động — giống StoreEmployeeShiftAssignmentRequest.
            // Sửa 1 bản gán ca đang trỏ tới Ca đã ngừng hoạt động thì bắt buộc
            // đổi sang Ca khác đang hoạt động, không giữ nguyên được.
            'work_shift_id' => ['required', Rule::exists('work_shifts', 'id')->where('is_active', 1)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
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
