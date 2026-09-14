<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.between' => 'Tháng phải từ 1 đến 12',
            'year.between' => 'Năm không hợp lệ',
        ];
    }
}
