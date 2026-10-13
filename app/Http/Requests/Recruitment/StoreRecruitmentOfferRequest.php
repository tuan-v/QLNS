<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecruitmentOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Phòng ban/chức vụ chốt cho ứng viên — điền sẵn theo đợt tuyển hoặc vị trí ứng tuyển.
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'position_id' => [
                'nullable', 'integer',
                Rule::exists('positions', 'id')->where('department_id', $this->input('department_id'))->whereNull('deleted_at'),
            ],
            'contract_type' => ['required', 'in:thu_viec,chinh_thuc,thuc_tap'],
            'salary' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'response_deadline' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:start_date'],
            'message' => ['nullable', 'string', 'max:2000'],
            // Người liên hệ ghi trong email (ứng viên gọi/nhắn để trả lời) — trống thì lấy HR đang soạn.
            // Email liên hệ không nhận từ form: luôn là email hệ thống công ty.
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +().-]{8,20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required' => 'Vui lòng chọn phòng ban',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'position_id.exists' => 'Chức vụ không thuộc phòng ban đã chọn',
            'contract_type.required' => 'Vui lòng chọn loại hợp đồng',
            'contract_type.in' => 'Loại hợp đồng không hợp lệ',
            'salary.required' => 'Vui lòng nhập mức lương',
            'salary.numeric' => 'Mức lương phải là số',
            'salary.min' => 'Mức lương không được âm',
            'salary.max' => 'Mức lương quá lớn',
            'start_date.required' => 'Vui lòng chọn ngày bắt đầu',
            'start_date.date' => 'Ngày bắt đầu không hợp lệ',
            'start_date.after_or_equal' => 'Ngày bắt đầu không được trước hôm nay',
            'response_deadline.required' => 'Vui lòng chọn hạn trả lời',
            'response_deadline.date' => 'Hạn trả lời không hợp lệ',
            'response_deadline.after_or_equal' => 'Hạn trả lời không được trước hôm nay',
            'response_deadline.before_or_equal' => 'Hạn trả lời phải trước hoặc bằng ngày bắt đầu',
            'contact_phone.regex' => 'Số điện thoại liên hệ không hợp lệ',
            'message.max' => 'Lời nhắn tối đa 2000 ký tự',
        ];
    }
}
