<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Onboarding / Offboarding: mẫu checklist (HR soạn sẵn) -> checklist của TỪNG nhân
// viên (chép nguyên các việc từ mẫu lúc tạo, sửa mẫu sau đó không ảnh hưởng
// checklist đã tạo). Xem ChecklistService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index(); // onboarding | offboarding
            $table->string('name', 255);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('responsible', 20); // hr | manager | employee
            // Hạn = ngày mốc + số ngày (âm = trước mốc). Mốc: ngày vào làm (onboarding)
            // hoặc ngày làm việc cuối (offboarding).
            $table->smallInteger('due_offset_days')->default(0);
            $table->boolean('is_required')->default(true);
            $table->string('auto_key', 40)->nullable(); // hệ thống tự đánh dấu xong
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('checklist_template_id')->nullable()->constrained('checklist_templates')->nullOnDelete();
            $table->string('template_name', 255)->nullable();
            $table->date('reference_date');
            $table->string('status', 20)->default('in_progress')->index(); // in_progress | completed | cancelled
            $table->foreignId('resignation_request_id')->nullable()->constrained('resignation_requests')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'type', 'status']);
        });

        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_checklist_id')->constrained('employee_checklists')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('responsible', 20);
            $table->date('due_date')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('auto_key', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        $this->seedDefaultTemplates();
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('employee_checklists');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }

    // 2 mẫu mặc định để dùng được ngay — HR sửa lại theo công ty ở trang Onboarding.
    private function seedDefaultTemplates(): void
    {
        $templates = [
            ['onboarding', 'Nhận việc — mặc định', [
                ['Tạo tài khoản đăng nhập', null, 'hr', 0, true, 'account_created'],
                ['Cấp email công ty', null, 'hr', 0, true, null],
                ['Cấp thiết bị làm việc (laptop, thẻ ra vào)', null, 'hr', 0, true, null],
                ['Ký hợp đồng lao động', 'Tải bản hợp đồng đã ký lên tab Hợp đồng của hồ sơ.', 'hr', 3, true, 'contract_signed'],
                ['Đọc và xác nhận nội quy công ty', null, 'employee', 2, true, null],
                ['Hoàn thiện hồ sơ cá nhân', 'CCCD, tài khoản ngân hàng, ảnh đại diện.', 'employee', 5, true, null],
                ['Giới thiệu đội nhóm và đào tạo hội nhập', null, 'manager', 5, true, null],
                ['Giao việc đầu tiên và người hướng dẫn', null, 'manager', 7, true, null],
            ]],
            ['offboarding', 'Nghỉ việc — mặc định', [
                ['Lập biên bản bàn giao công việc', null, 'employee', -5, true, null],
                ['Xác nhận đã nhận bàn giao', null, 'manager', -2, true, null],
                ['Phỏng vấn nghỉ việc', 'Ghi nhận lý do nghỉ và góp ý cho công ty.', 'hr', -1, false, null],
                ['Thu hồi thiết bị, thẻ ra vào', null, 'hr', 0, true, null],
                ['Khóa tài khoản đăng nhập', 'Hệ thống tự khóa sau ngày làm việc cuối.', 'hr', 1, true, 'account_locked'],
                ['Chấm dứt hợp đồng lao động', 'Hệ thống tự chấm dứt sau ngày làm việc cuối.', 'hr', 1, true, 'contract_ended'],
                ['Quyết toán lương, phép còn lại, BHXH', null, 'hr', 7, true, null],
            ]],
        ];

        foreach ($templates as [$type, $name, $items]) {
            $templateId = DB::table('checklist_templates')->insertGetId([
                'type' => $type,
                'name' => $name,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $index => [$title, $description, $responsible, $offset, $required, $autoKey]) {
                DB::table('checklist_template_items')->insert([
                    'checklist_template_id' => $templateId,
                    'title' => $title,
                    'description' => $description,
                    'responsible' => $responsible,
                    'due_offset_days' => $offset,
                    'is_required' => $required,
                    'auto_key' => $autoKey,
                    'sort_order' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
