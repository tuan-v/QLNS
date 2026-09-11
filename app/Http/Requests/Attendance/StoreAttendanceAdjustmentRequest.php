<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // 3 loại: 'correction' (mặc định — sửa 1 bản ghi attendances ĐÃ TỒN
    // TẠI, chỉ cần đề xuất 1 trong 2 mốc), 'supplement' (bổ sung — tạo bản
    // ghi cho 1 ca+ngày CHƯA từng chấm công, nên bắt buộc đủ cả giờ vào lẫn
    // giờ ra vì không có gì để "giữ nguyên" như correction), và 'excuse'
    // (Ngày 42 — xin miễn trừ đi muộn, KHÔNG sửa giờ nên không cần
    // proposed_check_in_at/proposed_check_out_at, chỉ cần lý do).
    public function rules(): array
    {
        $type = $this->input('type', 'correction');

        $rules = [
            'type' => ['nullable', 'in:correction,supplement,excuse'],
            'reason' => ['required', 'string', 'max:1000'],
        ];

        if ($type === 'supplement') {
            $rules['work_shift_id'] = ['required', 'integer', 'exists:work_shifts,id'];
            $rules['attendance_date'] = ['required', 'date', 'before_or_equal:today'];
            $rules['proposed_check_in_at'] = ['required', 'date'];
            $rules['proposed_check_out_at'] = ['required', 'date'];

            return $rules;
        }

        if ($type === 'excuse') {
            $rules['attendance_id'] = ['required', 'exists:attendances,id'];

            return $rules;
        }

        $rules['attendance_id'] = ['required', 'exists:attendances,id'];
        // Phải đề xuất ít nhất 1 trong 2 mốc — xin điều chỉnh mà không sửa
        // gì thì không có ý nghĩa. Cố tình KHÔNG validate proposed_check_out_at
        // phải sau proposed_check_in_at bằng rule "after" dựng sẵn — chỉ
        // gửi 1 trong 2 (sửa riêng giờ vào HOẶC riêng giờ ra) là chuyện
        // bình thường, rule "after" so với field rỗng sẽ báo lỗi sai. Thứ
        // tự giờ vào/ra khi CẢ HAI cùng có mặt được kiểm ở withValidator()
        // bên dưới thay vì rule "after" tĩnh.
        $rules['proposed_check_in_at'] = ['nullable', 'required_without:proposed_check_out_at', 'date'];
        $rules['proposed_check_out_at'] = ['nullable', 'required_without:proposed_check_in_at', 'date'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'attendance_id.required' => 'Vui lòng chọn bản ghi chấm công cần điều chỉnh',
            'attendance_id.exists' => 'Bản ghi chấm công không tồn tại',
            'work_shift_id.required' => 'Vui lòng chọn ca làm việc',
            'work_shift_id.exists' => 'Ca làm việc không tồn tại',
            'attendance_date.required' => 'Vui lòng chọn ngày cần bổ sung chấm công',
            'attendance_date.before_or_equal' => 'Không thể bổ sung chấm công cho ngày trong tương lai',
            'proposed_check_in_at.required' => 'Vui lòng nhập giờ vào',
            'proposed_check_in_at.required_without' => 'Vui lòng đề xuất ít nhất giờ vào hoặc giờ ra',
            'proposed_check_in_at.date' => 'Giờ vào đề xuất không đúng định dạng',
            'proposed_check_out_at.required' => 'Vui lòng nhập giờ ra',
            'proposed_check_out_at.required_without' => 'Vui lòng đề xuất ít nhất giờ vào hoặc giờ ra',
            'proposed_check_out_at.date' => 'Giờ ra đề xuất không đúng định dạng',
            'reason.required' => 'Vui lòng nhập lý do',
            'reason.max' => 'Lý do không được vượt quá 1000 ký tự',
        ];
    }

    // Không dùng rule "after:proposed_check_in_at" tĩnh (xem lý do ở rules())
    // nhưng vẫn phải chặn trường hợp cả 2 cùng gửi mà giờ ra <= giờ vào —
    // thiếu bước này thì AttendanceService::applyAdjustment() tính
    // actual_work_minutes bằng diffInMinutes() (luôn dương, không phân biệt
    // trước/sau) ra một con số "hợp lý" nhưng SAI hoàn toàn, HR duyệt theo
    // giao diện sẽ không phát hiện được vì số phút vẫn trông bình thường.
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $checkIn = $this->input('proposed_check_in_at');
            $checkOut = $this->input('proposed_check_out_at');

            if (! $checkIn || ! $checkOut) {
                return;
            }

            $checkInTimestamp = strtotime((string) $checkIn);
            $checkOutTimestamp = strtotime((string) $checkOut);

            // Sai định dạng thì rule "date" ở rules() đã báo lỗi riêng — chỉ
            // so sánh thứ tự khi cả 2 mốc đều là ngày giờ hợp lệ.
            if ($checkInTimestamp === false || $checkOutTimestamp === false) {
                return;
            }

            if ($checkOutTimestamp <= $checkInTimestamp) {
                $validator->errors()->add('proposed_check_out_at', 'Giờ ra đề xuất phải sau giờ vào đề xuất.');
            }
        });
    }
}
