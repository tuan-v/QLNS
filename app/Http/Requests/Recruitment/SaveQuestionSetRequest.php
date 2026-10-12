<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

// Admin tạo/sửa bộ câu hỏi phỏng vấn (câu hỏi + trọng số + % đạt).
class SaveQuestionSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'pass_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.content' => ['required', 'string', 'max:2000'],
            'questions.*.guidance' => ['nullable', 'string', 'max:2000'],
            'questions.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên bộ câu hỏi',
            'name.max' => 'Tên bộ câu hỏi tối đa 255 ký tự',
            'pass_percent.required' => 'Vui lòng nhập % đạt',
            'pass_percent.numeric' => '% đạt phải là số',
            'pass_percent.min' => '% đạt tối thiểu là 1',
            'pass_percent.max' => '% đạt tối đa là 100',
            'questions.required' => 'Bộ câu hỏi phải có ít nhất 1 câu',
            'questions.min' => 'Bộ câu hỏi phải có ít nhất 1 câu',
            'questions.max' => 'Bộ câu hỏi tối đa 50 câu',
            'questions.*.content.required' => 'Vui lòng nhập nội dung câu hỏi',
            'questions.*.content.max' => 'Câu hỏi tối đa 2000 ký tự',
            'questions.*.guidance.max' => 'Gợi ý tối đa 2000 ký tự',
            'questions.*.weight.required' => 'Vui lòng nhập trọng số',
            'questions.*.weight.integer' => 'Trọng số phải là số nguyên',
            'questions.*.weight.min' => 'Trọng số tối thiểu là 1',
            'questions.*.weight.max' => 'Trọng số tối đa là 100',
        ];
    }
}
