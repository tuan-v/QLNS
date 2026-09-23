<?php

namespace App\Http\Requests\WorkShift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "code" do hệ thống tự sinh lúc tạo, không cho sửa sau đó.
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            // Cùng quy tắc StoreWorkShiftRequest — xem comment ở đó.
            'break_start_time' => ['nullable', 'date_format:H:i', 'required_with:break_end_time'],
            'break_end_time' => ['nullable', 'date_format:H:i', 'required_with:break_start_time', 'after:break_start_time'],
            'standard_work_minutes' => ['required', 'integer', 'min:1'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0'],
            'early_leave_grace_minutes' => ['nullable', 'integer', 'min:0'],
            'work_coefficient' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            // Cùng quy tắc StoreWorkShiftRequest — xem comment ở đó.
            'work_days' => ['nullable', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên ca làm việc không được để trống',
            'name.max' => 'Tên ca làm việc không được vượt quá 100 ký tự',
            'start_time.required' => 'Giờ bắt đầu không được để trống',
            'start_time.date_format' => 'Giờ bắt đầu không đúng định dạng (HH:mm)',
            'end_time.required' => 'Giờ kết thúc không được để trống',
            'end_time.date_format' => 'Giờ kết thúc không đúng định dạng (HH:mm)',
            'break_start_time.date_format' => 'Giờ bắt đầu nghỉ trưa không đúng định dạng (HH:mm)',
            'break_start_time.required_with' => 'Vui lòng nhập giờ bắt đầu nghỉ trưa',
            'break_end_time.date_format' => 'Giờ kết thúc nghỉ trưa không đúng định dạng (HH:mm)',
            'break_end_time.required_with' => 'Vui lòng nhập giờ kết thúc nghỉ trưa',
            'break_end_time.after' => 'Giờ kết thúc nghỉ trưa phải sau giờ bắt đầu nghỉ trưa',
            'standard_work_minutes.required' => 'Số phút công chuẩn không được để trống',
            'standard_work_minutes.integer' => 'Số phút công chuẩn phải là số nguyên',
            'standard_work_minutes.min' => 'Số phút công chuẩn phải lớn hơn 0',
            'late_grace_minutes.integer' => 'Số phút châm chước đi muộn phải là số nguyên',
            'late_grace_minutes.min' => 'Số phút châm chước đi muộn không được nhỏ hơn 0',
            'early_leave_grace_minutes.integer' => 'Số phút châm chước về sớm phải là số nguyên',
            'early_leave_grace_minutes.min' => 'Số phút châm chước về sớm không được nhỏ hơn 0',
            'work_coefficient.numeric' => 'Hệ số công phải là số',
            'work_coefficient.min' => 'Hệ số công không được nhỏ hơn 0',
            'is_active.boolean' => 'Trạng thái phải là 1 hoặc 0',
            'is_default.boolean' => 'Ca mặc định phải là 1 hoặc 0',
            'work_days.min' => 'Vui lòng chọn ít nhất 1 ngày làm việc trong tuần',
            'work_days.*.between' => 'Ngày trong tuần không hợp lệ',
            'work_days.*.distinct' => 'Ngày trong tuần bị chọn trùng',
        ];
    }
}
