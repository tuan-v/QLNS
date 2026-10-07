<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'done' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'done.required' => 'Thiếu trạng thái hoàn thành',
            'done.boolean' => 'Trạng thái hoàn thành không hợp lệ',
            'note.max' => 'Ghi chú tối đa 1000 ký tự',
        ];
    }
}
