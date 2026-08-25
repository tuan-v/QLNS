<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            // manager_id: FK thêm sau ở migration riêng (tự tham chiếu employees.id)
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('code', 30)->unique();
            $table->string('full_name', 255);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('company_email', 255)->unique();
            $table->string('personal_email', 255)->nullable();
            $table->string('cccd', 30)->nullable()->unique();
            $table->text('addresses')->nullable();
            $table->string('personal_tax_code', 50)->nullable()->unique();
            $table->string('avatar', 500)->nullable();
            $table->date('hire_date');
            $table->date('probation_end_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('employment_status', 30)->default('probation');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
