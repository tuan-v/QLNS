<?php

namespace App\Http\Requests\Onboarding;

use App\Models\ChecklistTemplateItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'responsible' => ['required', Rule::in(ChecklistTemplateItem::RESPONSIBLES)],
            'due_date' => ['nullable', 'date'],
            'is_required' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên việc',
            'title.max' => 'Tên việc tối đa 255 ký tự',
            'description.max' => 'Mô tả tối đa 1000 ký tự',
            'responsible.required' => 'Vui lòng chọn người phụ trách',
            'responsible.in' => 'Người phụ trách không hợp lệ',
            'due_date.date' => 'Hạn không đúng định dạng ngày',
        ];
    }
}
