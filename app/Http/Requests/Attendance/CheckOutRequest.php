<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Cùng lý do CheckInRequest — chấm công RA cũng phải chỉ rõ
            // đang chấm cho ca nào (1 người có thể có nhiều ca cùng ngày).
            'work_shift_id' => ['required', 'integer', 'exists:work_shifts,id'],
            'method' => ['required', 'in:wifi,gps,qr'],
            'latitude' => ['required_if:method,gps', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:method,gps', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'qr_reference' => ['required_if:method,qr', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_shift_id.required' => 'Vui lòng chọn ca làm việc',
            'work_shift_id.exists' => 'Ca làm việc không tồn tại',
            'method.required' => 'Vui lòng chọn phương thức chấm công',
            'method.in' => 'Phương thức chấm công không hợp lệ',
            'latitude.required_if' => 'Không lấy được vị trí GPS, vui lòng thử lại',
            'longitude.required_if' => 'Không lấy được vị trí GPS, vui lòng thử lại',
            'qr_reference.required_if' => 'Vui lòng quét hoặc nhập mã QR',
        ];
    }
}
