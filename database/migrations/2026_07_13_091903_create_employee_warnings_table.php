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
        Schema::create('employee_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('letter_number')->unique()->comment('Contoh: 001/HRD-SP/08/2026');
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('level', ['SP1', 'SP2', 'SP3']);
            $table->date('issued_date');
            $table->date('expired_date')->nullable(); // Biasanya +6 bulan dari issued_date
            $table->text('reason');

            $table->string('attachment_path')->nullable()->comment('Scan PDF SP / BAP');
            $table->timestamp('acknowledged_at')->nullable()->comment('Waktu TTD/terima karyawan');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_warnings');
    }
};
