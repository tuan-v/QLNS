<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

// Nội dung văn bản thông báo nghỉ lễ (lưu nháp, phát hành, xem trước bản Word).
class SaveHolidayAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bonus = 'required_if_accepted:has_bonus';

        return [
            'document_number' => ['nullable', 'string', 'max:100'],
            'issued_date' => ['required', 'date'],
            'content' => ['required', 'array'],
            'content.company_type' => ['required', 'string', 'max:150'],
            'content.company_name' => ['required', 'string', 'max:150'],
            'content.city' => ['required', 'string', 'max:100'],
            'content.title' => ['required', 'string', 'max:255'],
            'content.intro' => ['required', 'string', 'max:1000'],
            'content.audience' => ['required', 'string', 'max:1000'],
            'content.responsibilities' => ['present', 'array', 'max:20'],
            'content.responsibilities.*' => ['nullable', 'string', 'max:500'],
            'content.closing' => ['nullable', 'string', 'max:1000'],
            'content.sign_off' => ['nullable', 'string', 'max:255'],
            'content.recipients' => ['present', 'array', 'max:10'],
            'content.recipients.*.text' => ['nullable', 'string', 'max:255'],
            'content.recipients.*.note' => ['nullable', 'string', 'max:100'],
            'content.bonus_heading' => ['nullable', 'string', 'max:255', $bonus],
            'content.bonus_pay_time' => ['nullable', 'string', 'max:500'],
            'content.bonus_method' => ['nullable', 'string', 'max:255'],
            'content.signer_title' => ['required', 'string', 'max:100'],
            'content.signer_name' => ['nullable', 'string', 'max:150'],
            'has_bonus' => ['required', 'boolean'],
            'bonus_rows' => ['nullable', 'array', 'max:10', $bonus],
            'bonus_rows.*.label' => ['required', 'string', 'max:255'],
            'bonus_rows.*.category' => ['nullable', 'in:official,probation,intern'],
            'bonus_rows.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'bonus_rows.*.note' => ['nullable', 'string', 'max:255'],
            'bonus_payroll_month' => ['nullable', 'integer', 'between:1,12', $bonus],
            'bonus_payroll_year' => ['nullable', 'integer', 'between:2000,2199', $bonus],
        ];
    }

    public function messages(): array
    {
        return [
            'issued_date.required' => 'Vui lòng chọn ngày ban hành',
            'content.company_type.required' => 'Vui lòng nhập loại hình công ty',
            'content.company_name.required' => 'Vui lòng nhập tên công ty',
            'content.city.required' => 'Vui lòng nhập nơi ban hành',
            'content.title.required' => 'Vui lòng nhập trích yếu (Về việc…)',
            'content.intro.required' => 'Vui lòng nhập câu mở đầu',
            'content.audience.required' => 'Vui lòng nhập đối tượng áp dụng',
            'content.signer_title.required' => 'Vui lòng nhập chức danh người ký',
            'content.bonus_heading.required_if_accepted' => 'Vui lòng nhập tiêu đề mục thưởng',
            'bonus_rows.required_if_accepted' => 'Vui lòng nhập bảng mức thưởng',
            'bonus_rows.*.label.required' => 'Vui lòng nhập vị trí',
            'bonus_rows.*.amount.required' => 'Vui lòng nhập mức thưởng',
            'bonus_rows.*.amount.min' => 'Mức thưởng không được âm',
            'bonus_payroll_month.required_if_accepted' => 'Vui lòng chọn kỳ lương chi thưởng',
            'bonus_payroll_year.required_if_accepted' => 'Vui lòng chọn kỳ lương chi thưởng',
        ];
    }
}
