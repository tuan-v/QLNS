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
            // "code" do hệ thống tự sinh lúc tạo, không cho sửa sau đó.
            'parent_id' => ['nullable', 'exists:departments,id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
    public function messages(): array
    {
        return [
            'name.required' => 'Tên phòng ban không được để trống',
            'parent_id.exists' => 'Phòng ban cha không tồn tại',
            'description.string' => 'Mô tả phải là chuỗi',
            'is_active.boolean' => 'Trạng thái phải là 1 hoặc 0',
        ];
    }
}
