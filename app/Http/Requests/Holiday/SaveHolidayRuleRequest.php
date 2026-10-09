<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

// Dùng chung cho tạo & sửa quy tắc lặp hằng năm. Khi sửa quy tắc theo luật
// (is_system), HolidayService bỏ qua tên/lịch/ngày gốc.
class SaveHolidayRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'calendar' => [$required, 'in:solar,lunar'],
            'month' => [$required, 'integer', 'between:1,12'],
            'day' => [$required, 'integer', 'between:1,31'],
            'offset_days' => ['sometimes', 'integer', 'between:-15,15'],
            'auto_adjacent' => ['sometimes', 'boolean'],
            'confirm_remind_days_before' => ['sometimes', 'integer', 'between:0,120'],
            'duration_days' => [$required, 'integer', 'between:1,30'],
            'compensate_weekend' => ['sometimes', 'boolean'],
            'is_paid' => ['sometimes', 'boolean'],
            'intern_paid' => ['sometimes', 'boolean'],
            'notify_days_before' => ['sometimes', 'integer', 'between:0,60'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    // Ngày phải tồn tại trong tháng: dương lịch theo số ngày của tháng (29/2 được
    // phép — năm không nhuận thì bỏ qua), âm lịch tối đa 30.
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $month = (int) $this->input('month');
            $day = (int) $this->input('day');
            if (! $this->filled(['month', 'day']) || $validator->errors()->hasAny(['month', 'day'])) {
                return;
            }

            $valid = $this->input('calendar', 'solar') === 'lunar'
                ? $day <= 30
                : checkdate($month, $day, 2000);

            if (! $valid) {
                $validator->errors()->add('day', "Ngày {$day} không tồn tại trong tháng {$month}.");
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên ngày nghỉ',
            'month.between' => 'Tháng từ 1 đến 12',
            'day.between' => 'Ngày từ 1 đến 31',
            'offset_days.between' => 'Độ lệch từ -15 đến 15 ngày',
            'duration_days.between' => 'Số ngày nghỉ từ 1 đến 30',
            'notify_days_before.between' => 'Báo trước từ 0 đến 60 ngày',
        ];
    }
}
