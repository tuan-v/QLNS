<?php

namespace App\Http\Requests\BankAccount;

use App\Models\EmployeeBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([EmployeeBankAccount::STATUS_VERIFIED, EmployeeBankAccount::STATUS_REJECTED])],
            'review_note' => ['nullable', 'required_if:status,rejected', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Thiếu kết quả xác nhận',
            'status.in' => 'Kết quả xác nhận không hợp lệ',
            'review_note.required_if' => 'Vui lòng nhập lý do từ chối',
            'review_note.max' => 'Lý do tối đa 500 ký tự',
        ];
    }
}
