<?php

namespace App\Http\Requests\Role;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', $this->input('guard_name', 'api')),
            ],
            'description' => ['nullable', 'string'],
            // Không bắt buộc: bỏ trống thì mặc định Role::DEFAULT_LEVEL = 100
            // (cấp cao nhất, chỉ Admin gán được) — chủ ý fail-closed.
            'level' => ['sometimes', 'integer', Rule::in(Role::LEVELS)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên vai trò không được để trống',
            'name.unique' => 'Tên vai trò này đã tồn tại',
            'level.integer' => 'Cấp bậc phải là số nguyên',
            'level.in' => 'Cấp bậc chỉ nhận các bậc '.implode(', ', Role::LEVELS),
        ];
    }
}
