<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class DecideAttendanceApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected'],
            // Từ chối bắt buộc có lý do (nhân viên cần biết vì sao bị từ chối để
            // còn "Xin điều chỉnh công"); duyệt thì ghi chú là tùy chọn.
            'decision_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn Duyệt hoặc Từ chối',
            'status.in' => 'Trạng thái duyệt không hợp lệ',
            'decision_note.required_if' => 'Vui lòng nhập lý do từ chối',
            'decision_note.max' => 'Ghi chú không được vượt quá 1000 ký tự',
        ];
    }
}
