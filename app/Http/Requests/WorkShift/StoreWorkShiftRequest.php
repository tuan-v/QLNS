<?php

namespace App\Http\Requests\WorkShift;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // "code" không nhận từ client — WorkShiftService tự sinh mã (CA001...).
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            // Chưa ràng buộc end_time phải sau start_time — dự án chưa có ca đêm
            // (qua nửa đêm) nên tạm để tự do, tính sau khi có nhu cầu thật.
            'end_time' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0'],
            'standard_work_minutes' => ['required', 'integer', 'min:1'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0'],
            'early_leave_grace_minutes' => ['nullable', 'integer', 'min:0'],
            'work_coefficient' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
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
            'break_minutes.integer' => 'Số phút nghỉ giữa ca phải là số nguyên',
            'break_minutes.min' => 'Số phút nghỉ giữa ca không được nhỏ hơn 0',
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
        ];
    }
}
