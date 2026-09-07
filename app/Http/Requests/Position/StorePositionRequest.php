<?php

namespace App\Http\Requests\Position;

use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            // "code" không nhận từ client — PositionService tự sinh mã (CV001...).
            'name' => ['required', 'string', 'max:150'],
            'level' => ['nullable', 'integer', 'min:1'],
            'position_allowance' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required' => 'Vui lòng chọn phòng ban',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'name.required' => 'Tên chức vụ không được để trống',
            'name.max' => 'Tên chức vụ không được vượt quá 150 ký tự',
            'level.integer' => 'Cấp bậc phải là số nguyên',
            'level.min' => 'Cấp bậc phải lớn hơn 0',
            'position_allowance.numeric' => 'Phụ cấp chức vụ phải là số',
            'position_allowance.min' => 'Phụ cấp chức vụ không được nhỏ hơn 0',
            'is_active.boolean' => 'Trạng thái phải là 1 hoặc 0',
        ];
    }
}
