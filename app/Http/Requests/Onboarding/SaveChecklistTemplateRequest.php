<?php

namespace App\Http\Requests\Onboarding;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// Dùng cho cả tạo và sửa mẫu. Sửa thì không đổi được loại (giữ loại của mẫu).
class SaveChecklistTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => [$this->route('template') ? 'nullable' : 'required', Rule::in(ChecklistTemplate::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.responsible' => ['required', Rule::in(ChecklistTemplateItem::RESPONSIBLES)],
            'items.*.due_offset_days' => ['nullable', 'integer', 'between:-60,90'],
            'items.*.is_required' => ['boolean'],
            'items.*.auto_key' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->route('template')?->type ?? $this->input('type');
            $allowed = ChecklistTemplateItem::AUTO_KEYS[$type] ?? [];

            foreach ((array) $this->input('items', []) as $index => $item) {
                $key = $item['auto_key'] ?? null;
                if ($key !== null && $key !== '' && ! in_array($key, $allowed, true)) {
                    $validator->errors()->add("items.{$index}.auto_key", 'Kiểu tự đánh dấu không dùng được cho loại checklist này');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Vui lòng chọn loại mẫu',
            'type.in' => 'Loại mẫu không hợp lệ',
            'name.required' => 'Vui lòng nhập tên mẫu',
            'name.max' => 'Tên mẫu tối đa 255 ký tự',
            'items.required' => 'Mẫu phải có ít nhất 1 việc',
            'items.min' => 'Mẫu phải có ít nhất 1 việc',
            'items.max' => 'Mẫu tối đa 50 việc',
            'items.*.title.required' => 'Vui lòng nhập tên việc',
            'items.*.title.max' => 'Tên việc tối đa 255 ký tự',
            'items.*.description.max' => 'Mô tả tối đa 1000 ký tự',
            'items.*.responsible.required' => 'Vui lòng chọn người phụ trách',
            'items.*.responsible.in' => 'Người phụ trách không hợp lệ',
            'items.*.due_offset_days.integer' => 'Số ngày phải là số nguyên',
            'items.*.due_offset_days.between' => 'Số ngày phải từ -60 đến 90',
        ];
    }
}
