<?php

namespace App\Http\Requests\Allowance;

use App\Services\AllowanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Thêm / sửa 1 khoản trong danh mục phụ cấp & trợ cấp.
class SaveAllowanceComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'component_type' => ['required', Rule::in(array_keys(AllowanceService::TYPES))],
            'calculation_method' => ['required', Rule::in(array_keys(AllowanceService::METHODS))],
            'is_taxable' => ['required', 'boolean'],
            'tax_free_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'in_insurance_salary' => ['required', 'boolean'],
            'applies_to_all' => ['sometimes', 'boolean'],
            'suggested_amounts' => ['required', 'array'],
            'suggested_amounts.chinh_thuc' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'suggested_amounts.thu_viec' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'suggested_amounts.thuc_tap' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'suggested_amounts.head' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên khoản',
            'component_type.required' => 'Vui lòng chọn loại',
            'calculation_method.required' => 'Vui lòng chọn cách tính',
            'suggested_amounts.*.numeric' => 'Mức gợi ý phải là số',
            'suggested_amounts.*.min' => 'Mức gợi ý không được âm',
            'tax_free_amount.min' => 'Mức miễn thuế không được âm',
        ];
    }
}
