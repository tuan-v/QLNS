<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocument extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'uploaded_by',
        'document_type',
        'document_name',
        'file_path',
        'file_size',
    ];

    /**
     * Tên tệp khi tải xuống: tên tài liệu người dùng đặt + đuôi của tệp gốc.
     *
     * Đuôi file KHÔNG lưu riêng trong DB mà nằm trong `file_path`, còn
     * `document_name` là tên do người dùng gõ (thường không kèm đuôi). Ghép ở
     * một chỗ duy nhất để EmployeeDocumentResource (`file_name` trả về cho
     * frontend, dùng để chọn kiểu xem trước) và EmployeeDocumentController
     * (tên tệp lúc tải xuống) không thể lệch nhau.
     */
    public function downloadFileName(): string
    {
        $extension = pathinfo($this->file_path, PATHINFO_EXTENSION);

        return $extension === '' ? $this->document_name : $this->document_name.'.'.$extension;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
