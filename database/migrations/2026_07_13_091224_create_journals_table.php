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
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued')->index();

            // Kolom invoice (Menggunakan loose relation + index untuk performa query pencarian)
            $table->string('invoice', 60)->index();
            $table->string('description', 160);

            // Otomatis terindeks & terkunci ke chart_of_accounts
            $table->foreignId('debt_id')->constrained('chart_of_accounts');
            $table->foreignId('cred_id')->constrained('chart_of_accounts');

            // Menggunakan unsigned jika nominal uang tidak pernah negatif
            $table->bigInteger('amount');
            $table->bigInteger('fee_amount');

            $table->integer('status')->default(1);
            $table->string('trx_type', 60)->nullable();
            $table->string('rcv_pay', 30)->nullable();
            $table->integer('payment_status')->nullable();
            $table->integer('payment_nth')->nullable();

            // Cara ringkas Laravel untuk membuat kolom sekaligus memasang Restrict on Delete
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('warehouse_id')->constrained()->onDelete('restrict');

            $table->string('serial_number', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
