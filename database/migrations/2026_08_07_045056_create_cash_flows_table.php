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
        Schema::create('cash_flows', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued')->index();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->onDelete("cascade");
            $table->enum('type', ['income', 'expense']);
            $table->string('category', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->boolean('is_corporate')->default(false);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['date_issued', 'journal_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_flows');
    }
};
