<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('employee_contract_id')->nullable()->constrained('employee_contracts')->nullOnDelete();
            $table->decimal('base_salary', 18, 2);
            $table->decimal('standard_work_days', 5, 2);
            $table->decimal('actual_work_days', 5, 2);
            $table->integer('overtime_minutes')->default(0);
            $table->decimal('total_allowance', 18, 2)->default(0);
            $table->decimal('insurance_amount', 18, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 18, 2)->default(0);
            $table->decimal('other_deduction', 18, 2)->default(0);
            $table->decimal('gross_salary', 18, 2)->default(0);
            $table->decimal('taxable_income', 18, 2)->default(0);
            $table->decimal('personal_income_tax', 18, 2)->default(0);
            $table->decimal('net_salary', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};
