<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nhân viên chọn rõ đang chấm công cho ca nào — 1 người có thể
            // có nhiều ca cùng ngày (mục 14), mỗi card ca ở CheckIn.vue gửi
            // đúng work_shift_id của chính nó, backend không tự đoán "ca
            // gần nhất" nữa (xem AttendanceService::checkIn()).
            'work_shift_id' => ['required', 'integer', 'exists:work_shifts,id'],
            // Không còn field 'method' (2026-09-21): nhân viên không chọn
            // Wifi/GPS/QR nữa — hệ thống tự ghi IP (server đọc từ request),
            // tên thiết bị (từ User-Agent) và vị trí (tọa độ trình duyệt gửi
            // lên) của thiết bị đang bấm. Vị trí và QR đều TÙY CHỌN: nhân
            // viên từ chối quyền vị trí vẫn chấm công được (status
            // needs_review nếu không khớp điểm nào). Tọa độ phải đi cặp
            // đôi — chỉ có 1 trong 2 là dữ liệu hỏng.
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
