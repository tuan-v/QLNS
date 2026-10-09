<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

// HR thêm ngày nghỉ riêng: chọn theo dương lịch (start_date) hoặc âm lịch
// (lunar_day/lunar_month/lunar_year, có thể là tháng nhuận) — HolidayService tự quy đổi.
class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'calendar' => ['required', 'in:solar,lunar'],
            'start_date' => ['required_if:calendar,solar', 'nullable', 'date'],
            'lunar_day' => ['required_if:calendar,lunar', 'nullable', 'integer', 'between:1,30'],
            'lunar_month' => ['required_if:calendar,lunar', 'nullable', 'integer', 'between:1,12'],
            'lunar_year' => ['required_if:calendar,lunar', 'nullable', 'integer', 'between:1900,2199'],
            'lunar_leap' => ['nullable', 'boolean'],
            'duration_days' => ['required', 'integer', 'between:1,30'],
            'is_paid' => ['nullable', 'boolean'],
            'intern_paid' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên ngày nghỉ',
            'start_date.required_if' => 'Vui lòng chọn ngày bắt đầu',
            'lunar_day.required_if' => 'Vui lòng nhập ngày âm lịch',
            'lunar_month.required_if' => 'Vui lòng nhập tháng âm lịch',
            'lunar_year.required_if' => 'Vui lòng nhập năm âm lịch',
            'lunar_day.between' => 'Ngày âm lịch từ 1 đến 30',
            'lunar_month.between' => 'Tháng âm lịch từ 1 đến 12',
            'duration_days.required' => 'Vui lòng nhập số ngày nghỉ',
            'duration_days.between' => 'Số ngày nghỉ từ 1 đến 30',
        ];
    }
}
