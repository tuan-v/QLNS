<?php

namespace App\Http\Requests\EmployeeAccount;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.required' => 'Chọn ít nhất 1 Role cho tài khoản.',
            'role_ids.min' => 'Chọn ít nhất 1 Role cho tài khoản.',
            'role_ids.*.exists' => 'Role không tồn tại.',
        ];
    }
}
