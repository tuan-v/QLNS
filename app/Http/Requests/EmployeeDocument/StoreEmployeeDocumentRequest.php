<?php

namespace App\Http\Requests\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:50'],
            'document_name' => ['required', 'string', 'max:255'],
            // Tài liệu cá nhân đa dạng hơn hợp đồng (CCCD, bằng cấp chụp ảnh,
            // sơ yếu lý lịch soạn bằng Word...) nên cho cả ảnh, PDF lẫn Word,
            // không chỉ riêng PDF như hợp đồng.
            // Chỉ nhận bản Office mới (.docx/.xlsx), KHÔNG nhận .doc/.xls
            // (Office 97-2003): hai đuôi cũ là định dạng nhị phân, docx-preview
            // và SheetJS ở FilePreviewDialog.vue không dựng được bản xem trước —
            // cho tải lên sẽ tạo ra tài liệu chỉ tải xuống được chứ không xem
            // được ngay trên giao diện.
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx,xlsx', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'Loại tài liệu không được để trống',
            'document_type.max' => 'Loại tài liệu không được vượt quá 50 ký tự',
            'document_name.required' => 'Tên tài liệu không được để trống',
            'document_name.max' => 'Tên tài liệu không được vượt quá 255 ký tự',
            'document_file.required' => 'Vui lòng đính kèm tệp tài liệu',
            'document_file.file' => 'Tệp đính kèm không hợp lệ',
            'document_file.mimes' => 'Tệp phải ở định dạng PDF, Word (.docx), Excel (.xlsx), JPG hoặc PNG',
            'document_file.max' => 'Dung lượng tệp không được vượt quá 10MB',
        ];
    }
}
