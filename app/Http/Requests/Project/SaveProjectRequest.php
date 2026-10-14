<?php

namespace App\Http\Requests\Project;

use App\Models\Employee;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Dùng chung tạo (POST) và sửa (PUT) dự án. Sửa: chỉ gửi trường cần đổi ("sometimes") —
// Lead chỉ được đổi trạng thái/mô tả/ngày kết thúc (ProjectService::update lọc lại).
class SaveProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'lead_employee_id' => [
                $required,
                'integer',
                Rule::exists('employees', 'id')->whereIn('employment_status', Employee::WORKING_STATUSES)->whereNull('deleted_at'),
            ],
            'start_date' => [$required, 'date'],
            // So với ngày bắt đầu chỉ khi có gửi kèm (Lead sửa riêng ngày kết thúc thì so ở Service).
            'end_date' => array_values(array_filter(['nullable', 'date', $this->filled('start_date') ? 'after_or_equal:start_date' : null])),
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên dự án',
            'name.max' => 'Tên dự án không được vượt quá 255 ký tự',
            'description.max' => 'Mô tả không được vượt quá 5000 ký tự',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'lead_employee_id.required' => 'Vui lòng chọn Lead dự án',
            'lead_employee_id.exists' => 'Lead phải là nhân viên đang làm việc',
            'start_date.required' => 'Vui lòng chọn ngày bắt đầu',
            'start_date.date' => 'Ngày bắt đầu không đúng định dạng',
            'end_date.date' => 'Ngày kết thúc không đúng định dạng',
            'end_date.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi',
            'status.in' => 'Trạng thái dự án không hợp lệ',
        ];
    }
}
