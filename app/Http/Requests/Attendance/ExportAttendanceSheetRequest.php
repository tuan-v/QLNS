<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ExportAttendanceSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Vui lòng chọn tháng',
            'month.between' => 'Tháng không hợp lệ',
            'year.required' => 'Vui lòng chọn năm',
            'year.between' => 'Năm không hợp lệ',
            'department_id.exists' => 'Phòng ban không tồn tại',
        ];
    }
}
