<?php

namespace App\Http\Requests\Allowance;

use Illuminate\Foundation\Http\FormRequest;

// Đặt mức 1 khoản phụ cấp cho 1 nhân viên (mức 0 = ngừng hưởng). Lý do bắt buộc khi khác mức gợi ý —
// kiểm ở AllowanceService::set() vì cần tính mức gợi ý của đúng nhân viên.
class SetEmployeeAllowanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salary_component_id' => ['required', 'integer', 'exists:salary_components,id'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Vui lòng nhập số tiền',
            'amount.min' => 'Số tiền không được âm',
            'amount.max' => 'Số tiền quá lớn',
            'effective_from.required' => 'Vui lòng chọn ngày áp dụng',
        ];
    }
}
