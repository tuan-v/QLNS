<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'unique:departments,code,' . $this->route('department')->id],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
    public function messages(): array
    {
        return [
            'name.required' => 'Tên phòng ban không được để trống',
            'code.required' => 'Mã phòng ban không được để trống',
            'code.unique' => 'Mã phòng ban đã tồn tại',
            'parent_id.exists' => 'Phòng ban cha không tồn tại',
            'description.string' => 'Mô tả phải là chuỗi',
            'is_active.boolean' => 'Trạng thái phải là 1 hoặc 0',
        ];
    }
}
