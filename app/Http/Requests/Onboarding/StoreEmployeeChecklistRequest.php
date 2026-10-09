<?php

namespace App\Http\Requests\Onboarding;

use App\Models\ChecklistTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(ChecklistTemplate::TYPES)],
            'template_id' => ['nullable', 'integer', Rule::exists('checklist_templates', 'id')->where('type', $this->input('type'))],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Vui lòng chọn nhân viên',
            'employee_id.exists' => 'Nhân viên không tồn tại',
            'type.required' => 'Vui lòng chọn loại checklist',
            'type.in' => 'Loại checklist không hợp lệ',
            'template_id.exists' => 'Mẫu checklist không tồn tại hoặc khác loại',
        ];
    }
}
