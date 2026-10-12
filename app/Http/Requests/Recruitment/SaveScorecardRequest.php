<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

// Lưu nháp / nộp phiếu chấm: điểm 1–5 từng câu (nháp được để trống) + nhận xét.
class SaveScorecardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'overall_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.score.integer' => 'Điểm phải là số nguyên từ 1 đến 5',
            'items.*.score.min' => 'Điểm thấp nhất là 1',
            'items.*.score.max' => 'Điểm cao nhất là 5',
            'items.*.note.max' => 'Ghi chú tối đa 1000 ký tự',
            'overall_note.max' => 'Nhận xét tối đa 2000 ký tự',
        ];
    }
}
