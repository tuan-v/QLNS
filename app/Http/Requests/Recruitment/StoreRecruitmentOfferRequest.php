<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecruitmentOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_type' => ['required', 'in:thu_viec,chinh_thuc,thuc_tap'],
            'salary' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'response_deadline' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:start_date'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
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
            'message.max' => 'Lời nhắn tối đa 2000 ký tự',
        ];
    }
}
