<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->smallInteger('year');
            $table->decimal('allocated_days', 5, 2)->default(0);
            $table->decimal('carried_forward_days', 5, 2)->default(0);
            $table->decimal('adjusted_days', 5, 2)->default(0);
            $table->decimal('used_days', 5, 2)->default(0);
            // remaining = allocated_days + carried_forward_days + adjusted_days - used_days
            // Giá trị tính toán, không lưu cột riêng trong DB
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
