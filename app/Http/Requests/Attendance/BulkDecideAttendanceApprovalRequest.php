<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

// Duyệt/Từ chối HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng, kèm ảnh tham
// khảo) — cùng luật với DecideAttendanceApprovalRequest (bản 1 dòng), chỉ
// thêm attendance_ids là mảng thay vì 1 {attendance} trên route.
class BulkDecideAttendanceApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendance_ids' => ['required', 'array', 'min:1'],
            'attendance_ids.*' => ['integer', 'exists:attendances,id'],
            'status' => ['required', 'in:approved,rejected'],
            'decision_note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'attendance_ids.required' => 'Chưa chọn bản ghi nào.',
            'status.required' => 'Vui lòng chọn Duyệt hoặc Từ chối',
            'status.in' => 'Trạng thái duyệt không hợp lệ',
            'decision_note.required_if' => 'Vui lòng nhập lý do từ chối',
            'decision_note.max' => 'Ghi chú không được vượt quá 1000 ký tự',
        ];
    }
}
