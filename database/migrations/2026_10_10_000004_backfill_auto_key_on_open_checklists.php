<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Checklist chép việc từ mẫu LÚC TẠO -> checklist tạo trước khi mẫu có kiểu tự đánh dấu
// mới (bank_account_verified, migration 2026_10_10_000003) không bao giờ tự tick. Bổ sung
// cho các checklist ĐANG LÀM: việc chưa xong, chưa có auto_key, cùng tên với việc trong
// mẫu gốc của nó đang có auto_key. Checklist đã xong/đã hủy giữ nguyên như lúc đó.
return new class () extends Migration {
    public function up(): void
    {
        $templateItems = DB::table('checklist_template_items')
            ->whereNotNull('auto_key')
            ->get(['checklist_template_id', 'title', 'auto_key']);

        foreach ($templateItems as $templateItem) {
            $checklistIds = DB::table('employee_checklists')
                ->where('checklist_template_id', $templateItem->checklist_template_id)
                ->where('status', 'in_progress')
                ->pluck('id');

            DB::table('employee_checklist_items')
                ->whereIn('employee_checklist_id', $checklistIds)
                ->where('title', $templateItem->title)
                ->whereNull('auto_key')
                ->whereNull('completed_at')
                ->update(['auto_key' => $templateItem->auto_key, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Không hoàn tác: chỉ bổ sung dữ liệu.
    }
};
