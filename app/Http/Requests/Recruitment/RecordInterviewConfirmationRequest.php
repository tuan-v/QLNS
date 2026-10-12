<?php

namespace App\Http\Requests\Recruitment;

use App\Models\RecruitmentInterview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// HR ghi nhận phản hồi của ứng viên với lịch phỏng vấn (không ghi "chờ xác nhận" tay).
class RecordInterviewConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                RecruitmentInterview::CONFIRMATION_CONFIRMED,
                RecruitmentInterview::CONFIRMATION_RESCHEDULE,
                RecruitmentInterview::CONFIRMATION_WITHDRAWN,
            ])],
            'note' => ['nullable', 'required_if:status,'.RecruitmentInterview::CONFIRMATION_WITHDRAWN, 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn phản hồi của ứng viên',
            'status.in' => 'Phản hồi không hợp lệ',
            'note.required_if' => 'Vui lòng ghi lý do ứng viên không tham gia',
            'note.max' => 'Ghi chú tối đa 1000 ký tự',
        ];
    }
}
