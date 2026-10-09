<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRecruitmentOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'required_if:status,rejected', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Thiếu kết quả duyệt',
            'status.in' => 'Kết quả duyệt không hợp lệ',
            'review_note.required_if' => 'Vui lòng nhập lý do không duyệt',
            'review_note.max' => 'Lý do tối đa 1000 ký tự',
        ];
    }
}
