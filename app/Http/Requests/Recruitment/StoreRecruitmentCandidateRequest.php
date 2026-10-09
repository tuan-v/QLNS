<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecruitmentCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // Cùng định dạng với hồ sơ nhân viên (StoreEmployeeRequest: digits:10) để
            // khi trúng tuyển, số này dùng được ngay ở form tạo nhân viên.
            'phone' => ['nullable', 'digits:10'],
            'note' => ['nullable', 'string', 'max:2000'],
            'cv_file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Vui lòng nhập họ tên ứng viên',
            'full_name.max' => 'Họ tên không được vượt quá 255 ký tự',
            'email.required' => 'Vui lòng nhập email ứng viên',
            'email.email' => 'Email không đúng định dạng',
            'phone.digits' => 'Số điện thoại phải gồm đúng 10 chữ số',
            'note.max' => 'Ghi chú không được vượt quá 2000 ký tự',
            'cv_file.required' => 'Vui lòng đính kèm CV',
            'cv_file.file' => 'Tệp CV không hợp lệ',
            'cv_file.mimes' => 'CV phải là tệp PDF',
            'cv_file.max' => 'CV không được vượt quá 10MB',
        ];
    }
}
