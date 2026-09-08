<?php

namespace App\Http\Requests\EmployeeTransfer;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_department_id' => ['required', 'exists:departments,id'],
            // null = giữ nguyên chức vụ/quản lý hiện tại, không bắt buộc phải
            // đổi cả 2 thứ cùng lúc với phòng ban mới.
            'new_position_id' => ['nullable', 'exists:positions,id'],
            'new_manager_id' => ['nullable', 'exists:employees,id'],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'decision_file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_department_id.required' => 'Phòng ban mới không được để trống',
            'to_department_id.exists' => 'Phòng ban mới không tồn tại',
            'new_position_id.exists' => 'Chức vụ mới không tồn tại',
            'new_manager_id.exists' => 'Quản lý mới không tồn tại',
            'effective_date.required' => 'Ngày hiệu lực không được để trống',
            'effective_date.date' => 'Ngày hiệu lực không đúng định dạng',
            'reason.max' => 'Lý do không được vượt quá 1000 ký tự',
            'decision_file.mimes' => 'Quyết định điều động phải ở định dạng PDF',
            'decision_file.max' => 'Dung lượng tệp không được vượt quá 10MB',
        ];
    }
}
