<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Cố tình KHÔNG dùng rule "exists:users,email" — nếu email sai thì
        // validate sẽ báo lỗi riêng, vô tình lộ ra hệ thống có/không có tài
        // khoản đó (User Enumeration). Chỉ kiểm tra định dạng, phần tồn tại
        // hay không xử lý âm thầm trong PasswordResetService.
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
        ];
    }
}
