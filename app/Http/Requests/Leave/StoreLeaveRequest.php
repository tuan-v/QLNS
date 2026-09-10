<?php

namespace App\Http\Requests\Leave;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'start_session' => ['nullable', 'in:full,am,pm'],
            'end_session' => ['nullable', 'in:full,am,pm'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'leave_type_id.required' => 'Vui lòng chọn loại phép',
            'leave_type_id.exists' => 'Loại phép không tồn tại hoặc đã ngừng sử dụng',
            'from_date.required' => 'Vui lòng chọn ngày bắt đầu',
            'to_date.required' => 'Vui lòng chọn ngày kết thúc',
            'to_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu',
            'start_session.in' => 'Buổi bắt đầu không hợp lệ',
            'end_session.in' => 'Buổi kết thúc không hợp lệ',
            'reason.required' => 'Vui lòng nhập lý do',
            'reason.max' => 'Lý do không được vượt quá 1000 ký tự',
        ];
    }

    // Xin nghỉ đúng 1 ngày (from_date === to_date) thì buổi bắt đầu/kết thúc
    // phải khớp nhau — nếu không sẽ không rõ đây là 1 ngày hay nửa ngày.
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');
            $startSession = $this->input('start_session', 'full');
            $endSession = $this->input('end_session', 'full');

            if ($fromDate && $toDate && $fromDate === $toDate && $startSession !== $endSession) {
                $validator->errors()->add('end_session', 'Xin nghỉ 1 ngày thì buổi bắt đầu và kết thúc phải giống nhau.');
            }
        });
    }
}
