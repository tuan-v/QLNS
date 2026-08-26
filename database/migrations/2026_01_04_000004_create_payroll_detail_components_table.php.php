<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payroll_detail_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_detail_id')->constrained('payroll_details')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained('salary_components')->restrictOnDelete();
            $table->string('component_name_snapshot', 150);
            $table->json('calculation_snapshot')->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 18, 4)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['payroll_detail_id', 'salary_component_id'], 'pdc_detail_component_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_detail_components');
    }
};
