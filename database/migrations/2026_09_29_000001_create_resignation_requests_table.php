<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đơn xin nghỉ việc (2026-09-29, theo yêu cầu người dùng) — xem
// App\Services\ResignationService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('resignation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // Ngày làm việc CUỐI CÙNG — từ ngày hôm sau nhân viên chuyển "Đã nghỉ việc".
            $table->date('last_working_date');
            $table->text('reason');
            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            // Thời điểm trạng thái nhân viên THẬT SỰ được chuyển sang "Đã nghỉ
            // việc" (sau ngày làm việc cuối) — null = đã duyệt nhưng chưa tới hạn.
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignation_requests');
    }
};
