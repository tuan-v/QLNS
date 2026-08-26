<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('contract_number', 50)->unique();
            $table->string('contract_type', 30);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('signed_at')->nullable();
            $table->decimal('agreed_salary', 18, 2);
            $table->decimal('insurance_salary', 18, 2);
            $table->string('status', 30)->default('active');
            $table->date('terminated_at')->nullable();
            $table->string('contract_file_path', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
