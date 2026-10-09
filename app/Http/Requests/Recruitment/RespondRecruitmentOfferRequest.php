<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

// Ứng viên trả lời thư mời (trang công khai, không đăng nhập).
class RespondRecruitmentOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,decline'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Vui lòng chọn chấp nhận hoặc từ chối',
            'decision.in' => 'Lựa chọn không hợp lệ',
            'note.max' => 'Lời nhắn tối đa 1000 ký tự',
        ];
    }
}
