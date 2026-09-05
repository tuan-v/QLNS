<?php

namespace App\Http\Requests\EmployeeContract;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_number' => ['required', 'string', 'max:50', 'unique:employee_contracts,contract_number'],
            'contract_type' => ['required', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'signed_at' => ['nullable', 'date'],
            'agreed_salary' => ['required', 'numeric', 'min:0'],
            'insurance_salary' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,expired,terminated'],
            'contract_file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contract_number.required' => 'Số hợp đồng không được để trống',
            'contract_number.max' => 'Số hợp đồng không được vượt quá 50 ký tự',
            'contract_number.unique' => 'Số hợp đồng đã tồn tại',
            'contract_type.required' => 'Loại hợp đồng không được để trống',
            'contract_type.max' => 'Loại hợp đồng không được vượt quá 30 ký tự',
            'start_date.required' => 'Ngày bắt đầu không được để trống',
            'start_date.date' => 'Ngày bắt đầu không đúng định dạng',
            'end_date.date' => 'Ngày kết thúc không đúng định dạng',
            'end_date.after' => 'Ngày kết thúc phải sau ngày bắt đầu',
            'signed_at.date' => 'Ngày ký không đúng định dạng',
            'agreed_salary.required' => 'Lương thỏa thuận không được để trống',
            'agreed_salary.numeric' => 'Lương thỏa thuận phải là số',
            'agreed_salary.min' => 'Lương thỏa thuận không được nhỏ hơn 0',
            'insurance_salary.required' => 'Lương đóng bảo hiểm không được để trống',
            'insurance_salary.numeric' => 'Lương đóng bảo hiểm phải là số',
            'insurance_salary.min' => 'Lương đóng bảo hiểm không được nhỏ hơn 0',
            'status.in' => 'Trạng thái hợp đồng không hợp lệ',
            'contract_file.required' => 'Vui lòng đính kèm file PDF hợp đồng',
            'contract_file.file' => 'Tệp đính kèm không hợp lệ',
            'contract_file.mimes' => 'File hợp đồng phải ở định dạng PDF',
            'contract_file.max' => 'Dung lượng file PDF không được vượt quá 5MB',
        ];
    }
}
