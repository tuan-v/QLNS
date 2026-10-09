<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class RecordRecruitmentInterviewResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'result' => ['required', 'in:passed,failed'],
            'result_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'result.required' => 'Vui lòng chọn kết quả phỏng vấn',
            'result.in' => 'Kết quả không hợp lệ',
            'result_note.max' => 'Nhận xét không được vượt quá 2000 ký tự',
        ];
    }
}
