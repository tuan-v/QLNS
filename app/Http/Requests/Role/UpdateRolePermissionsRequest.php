<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_ids.present' => 'Thiếu danh sách quyền',
            'permission_ids.array' => 'Danh sách quyền không hợp lệ',
            'permission_ids.*.exists' => 'Có quyền trong danh sách không tồn tại',
        ];
    }
}
