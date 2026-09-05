<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'unique:employees,company_email,' . $this->route('employee')->id],
            'hire_date' => ['required', 'date'],
            'personal_email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'cccd' => ['nullable', 'digits:12', 'unique:employees,cccd,' . $this->route('employee')->id],
            'personal_tax_code' => ['nullable', 'string', 'max:20', 'unique:employees,personal_tax_code,' . $this->route('employee')->id],
            'addresses' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'in:probation,active,resigned,terminated'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'manager_id' => ['nullable', 'exists:employees,id'],
            'user_id' => ['nullable', 'exists:users,id', 'unique:employees,user_id,' . $this->route('employee')->id],
            'termination_date' => ['nullable', 'date', 'after:hire_date'],
            'probation_end_date' => ['nullable', 'date', 'after:hire_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Tên nhân viên không được để trống',
            'full_name.string' => 'Tên nhân viên phải là chuỗi',
            'full_name.max' => 'Tên nhân viên không được vượt quá 255 ký tự',
            'company_email.required' => 'Email công ty không được để trống',
            'company_email.email' => 'Email công ty không đúng định dạng',
            'company_email.unique' => 'Email công ty đã tồn tại',
            'hire_date.required' => 'Ngày vào làm không được để trống',
            'hire_date.date' => 'Ngày vào làm không đúng định dạng',
            'personal_email.email' => 'Email cá nhân không đúng định dạng',
            'phone.string' => 'Số điện thoại phải là chuỗi',
            'phone.max' => 'Số điện thoại không được vượt quá 10 ký tự',
            'date_of_birth.date' => 'Ngày sinh không đúng định dạng',
            'date_of_birth.before' => 'Ngày sinh phải trước ngày hôm nay',
            'gender.in' => 'Giới tính không hợp lệ',
            'cccd.digits' => 'Số căn cước công dân phải là 12 chữ số',
            'cccd.unique' => 'Số căn cước công dân đã tồn tại',
            'personal_tax_code.string' => 'Mã số thuế cá nhân phải là chuỗi',
            'personal_tax_code.max' => 'Mã số thuế cá nhân không được vượt quá 20 ký tự',
            'personal_tax_code.unique' => 'Mã số thuế cá nhân đã tồn tại',
            'addresses.string' => 'Địa chỉ phải là chuỗi',
            'addresses.max' => 'Địa chỉ không được vượt quá 255 ký tự',
            'employment_status.in' => 'Trạng thái làm việc không hợp lệ',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'position_id.exists' => 'Chức vụ không tồn tại',
            'manager_id.exists' => 'Quản lý không tồn tại',
            'user_id.exists' => 'Người dùng không tồn tại',
            'user_id.unique' => 'Người dùng đã được liên kết với nhân viên khác',
            'termination_date.date' => 'Ngày chấm dứt hợp đồng không đúng định dạng',
            'termination_date.after' => 'Ngày chấm dứt hợp đồng phải sau ngày tuyển dụng',
            'probation_end_date.date' => 'Ngày kết thúc thử việc không đúng định dạng',
            'probation_end_date.after' => 'Ngày kết thúc thử việc phải sau ngày tuyển dụng',
        ];
    }
}
