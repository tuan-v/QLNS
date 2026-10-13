<?php

namespace App\Http\Requests\Recruitment;

use App\Models\RecruitmentOpening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Dùng chung tạo (POST) và sửa (PUT) đợt tuyển. Hạn nộp chỉ bắt "từ hôm nay" khi tạo
// mới — sửa đợt cũ đã quá hạn (vd chỉ sửa mô tả) không bị chặn vô lý.
class SaveRecruitmentOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'title' => ['required', 'string', 'max:255'],
            // Bỏ trống phòng ban = "tuyển dụng chung": không chức vụ, không giới hạn số lượng.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => [
                'nullable',
                'integer',
                Rule::prohibitedIf(! $this->filled('department_id')),
                Rule::exists('positions', 'id')->where('department_id', $this->input('department_id'))->whereNull('deleted_at'),
            ],
            // "tat_ca" = chưa cố định, chọn loại cụ thể khi soạn offer cho từng ứng viên.
            'contract_type' => ['required', Rule::in(RecruitmentOpening::CONTRACT_TYPES)],
            'headcount' => ['required_with:department_id', 'nullable', 'integer', 'min:1', 'max:100'],
            'deadline' => array_values(array_filter(['nullable', 'date', $isCreate ? 'after_or_equal:today' : null])),
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tên đợt tuyển',
            'title.max' => 'Tên đợt tuyển không được vượt quá 255 ký tự',
            'position_id.prohibited' => 'Đợt tuyển chung không chọn chức vụ — ứng viên tự chọn vị trí ứng tuyển',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'position_id.exists' => 'Chức vụ không thuộc phòng ban đã chọn',
            'contract_type.required' => 'Vui lòng chọn loại hợp đồng',
            'contract_type.in' => 'Loại hợp đồng không hợp lệ',
            'headcount.required_with' => 'Vui lòng nhập số người cần tuyển',
            'headcount.integer' => 'Số người cần tuyển phải là số nguyên',
            'headcount.min' => 'Số người cần tuyển tối thiểu là 1',
            'headcount.max' => 'Số người cần tuyển tối đa là 100',
            'deadline.date' => 'Hạn nộp không đúng định dạng',
            'deadline.after_or_equal' => 'Hạn nộp phải từ hôm nay trở đi',
            'description.max' => 'Mô tả không được vượt quá 5000 ký tự',
        ];
    }
}
