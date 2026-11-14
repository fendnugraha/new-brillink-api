<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->date('date');                          // Tanggal spesifik libur / event
            $table->date('end_date')->nullable();          // Opsional: Jika liburnya lebih dari 1 hari

            // Membedakan jenis liburnya dalam satu kolom
            $table->enum('type', [
                'weekly_off',    // Libur mingguan / rutin (misal: hari Minggu atau shift off)
                'leave',         // Cuti / Izin / Sakit pribadi
                'company_event'  // Libur event kantor / libur nasional bersama
            ]);

            $table->string('title')->nullable();           // Keterangan / Nama Event / Alasan cuti
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved'); // Status (libur rutin/event otomatis approved)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_schedules');
    }
};
