<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

// Admin duyệt/từ chối nhiều CV cùng lúc — từng CV vẫn đi qua RecruitmentService::reviewCandidate()
// (đủ suất thì CV dư báo lỗi riêng, không làm hỏng các CV khác).
class BulkReviewRecruitmentCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_ids' => ['required', 'array', 'min:1', 'max:100'],
            'candidate_ids.*' => ['integer', 'distinct', 'exists:recruitment_candidates,id'],
            'status' => ['required', 'in:approved,rejected'],
            'review_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'candidate_ids.required' => 'Vui lòng chọn ít nhất 1 CV',
            'candidate_ids.max' => 'Mỗi lần xử lý tối đa 100 CV',
            'status.in' => 'Quyết định không hợp lệ',
            'review_note.required_if' => 'Vui lòng nhập lý do từ chối',
        ];
    }
}
