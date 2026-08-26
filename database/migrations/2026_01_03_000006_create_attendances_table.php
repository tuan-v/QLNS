<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->restrictOnDelete();
            $table->date('attendance_date');
            $table->dateTime('first_check_in_at')->nullable();
            $table->dateTime('last_check_out_at')->nullable();
            $table->smallInteger('scheduled_work_minutes')->default(0);
            $table->smallInteger('actual_work_minutes')->default(0);
            $table->smallInteger('overtime_minutes')->default(0);
            $table->smallInteger('late_minutes')->default(0);
            $table->smallInteger('early_leave_minutes')->default(0);
            $table->string('status', 30)->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
