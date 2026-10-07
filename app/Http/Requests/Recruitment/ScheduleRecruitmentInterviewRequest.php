<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Trực tiếp: Tỉnh/Thành + Xã/Phường (thuộc đúng tỉnh) + địa chỉ chi tiết — Service ghép
// thành địa chỉ đầy đủ. Trực tuyến: link http(s). Không bắt chọn người phỏng vấn.
class ScheduleRecruitmentInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $onsite = $this->input('format') === 'onsite';

        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
            'format' => ['required', 'in:onsite,online'],
            'location' => [Rule::requiredIf(! $onsite), 'nullable', 'string', 'max:500', Rule::when(! $onsite, ['url:http,https'])],
            'province_code' => [Rule::requiredIf($onsite), 'nullable', 'integer', 'exists:provinces,code'],
            'commune_code' => [
                Rule::requiredIf($onsite), 'nullable', 'integer',
                Rule::exists('communes', 'code')->where('province_code', $this->input('province_code')),
            ],
            'address_detail' => [Rule::requiredIf($onsite), 'nullable', 'string', 'max:255'],
            'interviewer_id' => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Vui lòng chọn thời gian phỏng vấn',
            'scheduled_at.date' => 'Thời gian phỏng vấn không hợp lệ',
            'scheduled_at.after' => 'Thời gian phỏng vấn phải ở tương lai',
            'format.required' => 'Vui lòng chọn hình thức phỏng vấn',
            'format.in' => 'Hình thức phỏng vấn không hợp lệ',
            'location.required' => 'Vui lòng nhập link phỏng vấn',
            'location.url' => 'Phỏng vấn trực tuyến cần link hợp lệ (bắt đầu bằng http:// hoặc https://)',
            'location.max' => 'Link không được vượt quá 500 ký tự',
            'province_code.required' => 'Vui lòng chọn Tỉnh/Thành phố',
            'province_code.exists' => 'Tỉnh/Thành phố không tồn tại',
            'commune_code.required' => 'Vui lòng chọn Xã/Phường',
            'commune_code.exists' => 'Xã/Phường không thuộc Tỉnh/Thành phố đã chọn',
            'address_detail.required' => 'Vui lòng nhập địa chỉ chi tiết',
            'address_detail.max' => 'Địa chỉ chi tiết không được vượt quá 255 ký tự',
            'interviewer_id.exists' => 'Người phỏng vấn không tồn tại',
        ];
    }
}
