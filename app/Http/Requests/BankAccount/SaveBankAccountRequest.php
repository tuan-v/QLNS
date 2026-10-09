<?php

namespace App\Http\Requests\BankAccount;

use App\Support\VietnamBanks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Dùng chung cho nhân viên tự nhập và HR nhập. Chuẩn hóa trước khi kiểm tra: bỏ khoảng
// trắng trong số tài khoản; tên chủ tài khoản viết HOA không dấu (đúng như ngân hàng in).
class SaveBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_number' => preg_replace('/\s+/', '', (string) $this->input('account_number')),
            'account_holder' => Str::upper(trim(preg_replace('/\s+/', ' ', Str::ascii((string) $this->input('account_holder'))))),
        ]);
    }

    public function rules(): array
    {
        return [
            'bank_code' => ['required', Rule::in(array_keys(VietnamBanks::ALL))],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'account_number' => ['required', 'regex:/^\d{6,20}$/'],
            'account_holder' => ['required', 'max:255', 'regex:/^[A-Z ]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_code.required' => 'Vui lòng chọn ngân hàng',
            'bank_code.in' => 'Ngân hàng không hợp lệ',
            'bank_branch.max' => 'Chi nhánh tối đa 150 ký tự',
            'account_number.required' => 'Vui lòng nhập số tài khoản',
            'account_number.regex' => 'Số tài khoản chỉ gồm chữ số, từ 6 đến 20 số',
            'account_holder.required' => 'Vui lòng nhập tên chủ tài khoản',
            'account_holder.max' => 'Tên chủ tài khoản tối đa 255 ký tự',
            'account_holder.regex' => 'Tên chủ tài khoản chỉ gồm chữ cái và khoảng trắng',
        ];
    }
}
