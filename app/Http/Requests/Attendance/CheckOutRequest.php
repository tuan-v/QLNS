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
            // Cùng quy tắc CheckInRequest: không có 'method', vị trí/QR tùy
            // chọn, tọa độ đi cặp đôi.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'qr_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_shift_id.required' => 'Vui lòng chọn ca làm việc',
            'work_shift_id.exists' => 'Ca làm việc không tồn tại',
            'latitude.required_with' => 'Tọa độ vị trí không đầy đủ, vui lòng thử lại',
            'longitude.required_with' => 'Tọa độ vị trí không đầy đủ, vui lòng thử lại',
        ];
    }
}
