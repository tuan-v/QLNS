<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z][a-z_]*\.[a-z][a-z_]*$/',
                Rule::unique('permissions', 'code')
                    ->where('guard_name', $this->input('guard_name', 'api'))
                    ->ignore($this->route('permission')),
            ],
            'name' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Mã quyền không được để trống',
            'code.regex' => 'Mã quyền phải theo dạng "nhom.hanh_dong" (chữ thường, cách nhau bằng dấu chấm), ví dụ: employee.view',
            'code.unique' => 'Mã quyền này đã tồn tại',
            'name.required' => 'Tên quyền không được để trống',
        ];
    }
}
