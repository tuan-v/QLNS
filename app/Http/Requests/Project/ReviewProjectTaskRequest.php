<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

// Lead duyệt công việc: duyệt bắt buộc chấm chất lượng 1-5, trả lại bắt buộc ghi lý do.
class ReviewProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,return'],
            'quality_score' => ['required_if:decision,approve', 'nullable', 'integer', 'between:1,5'],
            'note' => ['required_if:decision,return', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Vui lòng chọn duyệt hoặc trả lại',
            'decision.in' => 'Quyết định không hợp lệ',
            'quality_score.required_if' => 'Vui lòng chấm chất lượng (1-5 sao)',
            'quality_score.between' => 'Điểm chất lượng từ 1 đến 5',
            'note.required_if' => 'Vui lòng ghi lý do trả lại để người làm biết cần sửa gì',
            'note.max' => 'Nhận xét không được vượt quá 2000 ký tự',
        ];
    }
}
