<?php

namespace App\Http\Requests\EmployeeContract;

use App\Models\EmployeeContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Bổ sung dữ liệu còn THIẾU của 1 hợp đồng đã tạo — xem
// EmployeeContractService::fillMissing(). Cố ý KHÔNG nhận contract_type /
// start_date / agreed_salary: điều khoản đã ký là bất biến, muốn đổi thì ký
// hợp đồng MỚI qua StoreEmployeeContractRequest.
class UpdateEmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contract = $this->route('contract');
        $startDate = $contract instanceof EmployeeContract
            ? $contract->start_date?->toDateString()
            : null;
        $isReplacingFile = $contract instanceof EmployeeContract
            && $contract->contract_file_path !== null
            && $this->hasFile('contract_file');

        return [
            // So với ngày bắt đầu của CHÍNH hợp đồng đang sửa (không có trong
            // payload nên lấy từ route model), giữ đúng luật của lúc tạo mới.
            'end_date' => array_filter(['nullable', 'date', $startDate ? 'after:'.$startDate : null]),
            'signed_at' => ['nullable', 'date'],
            'contract_file' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            // Tải tệp ĐẦU TIÊN thì không cần lý do; THAY tệp đã có thì bắt buộc
            // — đó là thứ duy nhất giải thích được vì sao bản ký đính kèm đổi,
            // và nó được ghi vào audit log cùng tệp cũ/tệp mới.
            'replace_reason' => [
                Rule::requiredIf(fn () => $isReplacingFile),
                'nullable', 'string', 'min:5', 'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.date' => 'Ngày kết thúc không đúng định dạng',
            'end_date.after' => 'Ngày kết thúc phải sau ngày bắt đầu hợp đồng',
            'signed_at.date' => 'Ngày ký không đúng định dạng',
            'contract_file.file' => 'Tệp đính kèm không hợp lệ',
            'contract_file.mimes' => 'File hợp đồng phải ở định dạng PDF',
            'contract_file.max' => 'Dung lượng file PDF không được vượt quá 5MB',
            'replace_reason.required' => 'Hợp đồng này đã có tệp PDF — vui lòng nhập lý do thay tệp',
            'replace_reason.min' => 'Lý do thay tệp phải có ít nhất 5 ký tự',
            'replace_reason.max' => 'Lý do thay tệp không được vượt quá 500 ký tự',
        ];
    }
}
