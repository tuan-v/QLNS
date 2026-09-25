<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

// Duyệt/Từ chối đơn nghỉ phép HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng,
// kèm ảnh tham khảo) — cùng luật với DecideLeaveRequest (bản 1 đơn), chỉ
// thêm leave_request_ids là mảng thay vì 1 {leaveRequest} trên route.
class BulkDecideLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leave_request_ids' => ['required', 'array', 'min:1'],
            'leave_request_ids.*' => ['integer', 'exists:leave_requests,id'],
            'status' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'leave_request_ids.required' => 'Chưa chọn đơn nào.',
            'status.required' => 'Vui lòng chọn Duyệt hoặc Từ chối',
            'status.in' => 'Trạng thái duyệt không hợp lệ',
            'comment.max' => 'Ghi chú không được vượt quá 1000 ký tự',
        ];
    }
}
