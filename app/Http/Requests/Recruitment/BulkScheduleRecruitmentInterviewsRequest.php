<?php

namespace App\Http\Requests\Recruitment;

// Hẹn phỏng vấn nhiều ứng viên theo khung giờ: ứng viên thứ i được hẹn lúc
// start_at + i × slot_minutes, cùng hình thức/địa điểm. Kế thừa đúng rule địa điểm của
// hẹn từng người (ScheduleRecruitmentInterviewRequest) để 2 nơi không lệch nhau.
class BulkScheduleRecruitmentInterviewsRequest extends ScheduleRecruitmentInterviewRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['scheduled_at'], $rules['interviewer_id']);

        return $rules + [
            'candidate_ids' => ['required', 'array', 'min:1', 'max:50'],
            'candidate_ids.*' => ['integer', 'distinct', 'exists:recruitment_candidates,id'],
            'start_at' => ['required', 'date', 'after:now'],
            'slot_minutes' => ['required', 'integer', 'min:10', 'max:240'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'candidate_ids.required' => 'Vui lòng chọn ít nhất 1 ứng viên',
            'candidate_ids.max' => 'Mỗi lần hẹn tối đa 50 ứng viên',
            'start_at.required' => 'Vui lòng chọn giờ bắt đầu',
            'start_at.after' => 'Giờ bắt đầu phải ở tương lai',
            'slot_minutes.required' => 'Vui lòng chọn thời lượng mỗi lượt',
            'slot_minutes.min' => 'Mỗi lượt tối thiểu 10 phút',
            'slot_minutes.max' => 'Mỗi lượt tối đa 240 phút',
        ];
    }
}
