<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Giao (POST) / sửa (PUT) công việc. "Người được giao là thành viên dự án" kiểm ở
// ProjectTaskService (cần biết dự án). Hạn chót chỉ bắt "từ hôm nay" khi giao mới.
class SaveProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $required = $isCreate ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_employee_id' => ['nullable', 'integer'],
            'priority' => [$required, Rule::in(ProjectTask::PRIORITIES)],
            'points' => [$required, 'integer', Rule::in(ProjectTask::POINTS)],
            'start_date' => ['nullable', 'date'],
            'due_date' => array_values(array_filter([
                $required,
                'date',
                $this->filled('start_date') ? 'after_or_equal:start_date' : null,
                $isCreate ? 'after_or_equal:today' : null,
            ])),
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên công việc',
            'title.max' => 'Tên công việc không được vượt quá 255 ký tự',
            'description.max' => 'Mô tả không được vượt quá 5000 ký tự',
            'priority.required' => 'Vui lòng chọn độ ưu tiên',
            'priority.in' => 'Độ ưu tiên không hợp lệ',
            'points.required' => 'Vui lòng chọn độ khó',
            'points.in' => 'Độ khó phải là 1, 2, 3, 5 hoặc 8',
            'start_date.date' => 'Ngày bắt đầu không đúng định dạng',
            'due_date.required' => 'Vui lòng chọn hạn hoàn thành',
            'due_date.date' => 'Hạn hoàn thành không đúng định dạng',
            'due_date.after_or_equal' => 'Hạn hoàn thành phải từ hôm nay và không trước ngày bắt đầu',
        ];
    }
}
