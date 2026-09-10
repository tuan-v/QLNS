<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Chỉ cho tự sửa THÔNG TIN LIÊN HỆ (điện thoại, email cá nhân, địa chỉ) — cố
// tình KHÔNG cho sửa qua đây: field định danh/pháp lý (CCCD, mã số thuế cá
// nhân, ngày sinh, giới tính — ảnh hưởng hồ sơ lương/thuế, cần HR duyệt lại
// mới đổi) và field công việc (department_id/position_id/manager_id/
// employment_status/company_email... — không phải nhân viên tự quyết).
// Muốn sửa các field đó vẫn phải qua UpdateEmployeeRequest (permission
// employee.update, HR/Admin).
class UpdateMyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Route /employees/me không có tham số {employee} nên không lấy được
        // qua $this->route('employee') như UpdateEmployeeRequest — loại trừ
        // chính bản ghi đang sửa qua employee đã liên kết với user đăng nhập.
        $employeeId = $this->user()->employee?->id;

        return [
            'phone' => ['required', 'string', 'max:10', 'unique:employees,phone,'.$employeeId],
            'personal_email' => ['required', 'email', 'unique:employees,personal_email,'.$employeeId],
            'address_detail' => ['required', 'string', 'max:255'],
            'province_code' => ['required', 'integer', 'exists:provinces,code'],
            'commune_code' => [
                'required',
                'integer',
                Rule::exists('communes', 'code')->where('province_code', $this->input('province_code')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Số điện thoại không được để trống',
            'phone.max' => 'Số điện thoại không được vượt quá 10 ký tự',
            'phone.unique' => 'Số điện thoại đã tồn tại',
            'personal_email.required' => 'Email cá nhân không được để trống',
            'personal_email.email' => 'Email cá nhân không đúng định dạng',
            'personal_email.unique' => 'Email cá nhân đã tồn tại',
            'address_detail.required' => 'Địa chỉ chi tiết không được để trống',
            'address_detail.max' => 'Địa chỉ chi tiết không được vượt quá 255 ký tự',
            'province_code.required' => 'Tỉnh/Thành phố không được để trống',
            'province_code.exists' => 'Tỉnh/Thành phố không tồn tại',
            'commune_code.required' => 'Xã/Phường không được để trống',
            'commune_code.exists' => 'Xã/Phường không tồn tại hoặc không thuộc Tỉnh/Thành phố đã chọn',
        ];
    }
}
