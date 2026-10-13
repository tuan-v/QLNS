<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

// Gắn các bộ câu hỏi (kèm tỉ trọng %) cho đợt tuyển hoặc làm mặc định của chức vụ.
// Tổng tỉ trọng = 100 và bộ còn dùng được: InterviewScorecardService::syncSets() kiểm tra.
class AssignQuestionSetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_sets' => ['present', 'array', 'max:10'],
            'question_sets.*.id' => ['required', 'integer', 'distinct'],
            'question_sets.*.weight' => ['required', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'question_sets.*.id.distinct' => 'Mỗi bộ câu hỏi chỉ chọn một lần',
            'question_sets.*.weight.required' => 'Vui lòng nhập tỉ trọng',
            'question_sets.*.weight.between' => 'Tỉ trọng từ 1 đến 100%',
        ];
    }
}
