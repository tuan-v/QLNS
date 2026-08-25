<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->foreignId('attendance_location_id')->nullable()->constrained('attendance_locations')->nullOnDelete();
            $table->string('event_type', 20); // check_in / check_out
            $table->dateTime('occurred_at');
            $table->string('method', 20); // wifi / gps / qr
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('qr_reference', 255)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
