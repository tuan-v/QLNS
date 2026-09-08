<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    public function toArray($request)
    {
        // store() không tự eager-load như index() — loadMissing() chỉ query
        // nếu quan hệ chưa nạp, nên index() (đã with() sẵn) không tốn thêm.
        $this->resource->loadMissing('uploader');

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_name' => $this->document_name,
            'file_size' => $this->file_size,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->user_name),
            'created_at' => $this->created_at,
            'download_url' => route('employees.documents.download', [
                'employee' => $this->employee_id,
                'document' => $this->id,
            ]),
            'file_name' => $this->resource->downloadFileName(),
        ];
    }
}
