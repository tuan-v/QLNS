<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
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
            'start_session' => ['nullable', 'in:full,am,pm,hourly'],
            'end_session' => ['nullable', 'in:full,am,pm,hourly'],
            // Chỉ bắt buộc khi start_session='hourly' — xem withValidator().
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'max:1000'],
            // Không bắt buộc ở đây — 1 số loại phép (ốm, thai sản, chế độ
            // cha/mẹ) bắt buộc đính kèm, kiểm tra tùy loại ở withValidator().
            'evidence_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'start_time.date_format' => 'Giờ bắt đầu không đúng định dạng (HH:mm)',
            'end_time.date_format' => 'Giờ kết thúc không đúng định dạng (HH:mm)',
            'end_time.after' => 'Giờ kết thúc phải sau giờ bắt đầu',
            'reason.required' => 'Vui lòng nhập lý do',
            'reason.max' => 'Lý do không được vượt quá 1000 ký tự',
            'evidence_file.mimes' => 'Tài liệu đính kèm phải là ảnh (jpg, png) hoặc PDF',
            'evidence_file.max' => 'Tài liệu đính kèm không được vượt quá 5MB',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');
            $startSession = $this->input('start_session', 'full');
            $endSession = $this->input('end_session', 'full');

            if ($startSession === 'hourly' || $endSession === 'hourly') {
                // Nghỉ theo giờ chỉ áp dụng cho ĐÚNG 1 ngày — không có khái
                // niệm "giờ bắt đầu ở ngày A, giờ kết thúc ở ngày B".
                if ($fromDate && $toDate && $fromDate !== $toDate) {
                    $validator->errors()->add('to_date', 'Nghỉ theo giờ chỉ áp dụng cho đúng 1 ngày.');
                }
                if ($startSession !== 'hourly' || $endSession !== 'hourly') {
                    $validator->errors()->add('end_session', 'Nghỉ theo giờ thì buổi bắt đầu và kết thúc đều phải là "Theo giờ".');
                }
                if (! $this->filled('start_time') || ! $this->filled('end_time')) {
                    $validator->errors()->add('start_time', 'Vui lòng nhập giờ bắt đầu và giờ kết thúc.');
                }

                return;
            }

            // Xin nghỉ đúng 1 ngày (from_date === to_date) thì buổi bắt
            // đầu/kết thúc phải khớp nhau — nếu không sẽ không rõ đây là 1
            // ngày hay nửa ngày.
            if ($fromDate && $toDate && $fromDate === $toDate && $startSession !== $endSession) {
                $validator->errors()->add('end_session', 'Xin nghỉ 1 ngày thì buổi bắt đầu và kết thúc phải giống nhau.');
            }
        });

        // Ốm/Thai sản/Chế độ cha-mẹ bắt buộc đính kèm giấy tờ (khám bệnh,
        // giấy chứng sinh...) — các loại khác thì tùy chọn.
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('leave_type_id') || $this->hasFile('evidence_file')) {
                return;
            }

            $leaveType = LeaveType::find($this->input('leave_type_id'));
            $requiresEvidence = in_array($leaveType?->code, ['sick', 'maternity', 'paternity'], true);

            if ($requiresEvidence) {
                $validator->errors()->add('evidence_file', 'Loại phép này bắt buộc đính kèm tài liệu (giấy khám bệnh, giấy chứng sinh...).');
            }
        });
    }
}
