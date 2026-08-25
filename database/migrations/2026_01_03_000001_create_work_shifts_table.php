<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->smallInteger('break_minutes')->default(0);
            $table->smallInteger('standard_work_minutes');
            $table->smallInteger('late_grace_minutes')->default(0);
            $table->smallInteger('early_leave_grace_minutes')->default(0);
            $table->decimal('work_coefficient', 5, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
    }
};
