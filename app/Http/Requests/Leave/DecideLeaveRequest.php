<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class DecideLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn Duyệt hoặc Từ chối',
            'status.in' => 'Trạng thái duyệt không hợp lệ',
            'comment.max' => 'Ghi chú không được vượt quá 1000 ký tự',
        ];
    }
}
