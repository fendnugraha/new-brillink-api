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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relasi ke Jurnal (Source untuk ref_no, amount, notes)
            $table->foreignId('journal_id')->constrained('journals')->onDelete('cascade');

            // Akun Kas Asal & Tujuan (Warehouse otomatis ikut dari relasi COA)
            $table->foreignId('source_account_id')->constrained('chart_of_accounts');
            $table->foreignId('destination_account_id')->constrained('chart_of_accounts');

            // Petugas/Kurir Internal (dari tabel employees)
            $table->foreignId('courier_id')->nullable()->constrained('employees');

            // Penerima Kas di Toko Cabang Tujuan (dari tabel employees)
            $table->foreignId('received_by_id')->nullable()->constrained('employees');
            $table->timestamp('received_at')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            // Status Operasional Fisik Uang Uang
            $table->enum('status', ['pending', 'picked_up', 'in_transit', 'delivered', 'cancelled'])
                ->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
