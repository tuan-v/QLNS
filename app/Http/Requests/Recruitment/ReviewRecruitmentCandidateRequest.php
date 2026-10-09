<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRecruitmentCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected'],
            // Từ chối bắt buộc có lý do để HR biết vì sao.
            'review_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn Duyệt hoặc Từ chối',
            'status.in' => 'Quyết định không hợp lệ',
            'review_note.required_if' => 'Vui lòng nhập lý do từ chối',
            'review_note.max' => 'Ghi chú không được vượt quá 1000 ký tự',
        ];
    }
}
