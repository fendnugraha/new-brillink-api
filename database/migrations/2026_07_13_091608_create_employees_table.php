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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->date('hire_date');
            $table->string('id_card_number', 20)->nullable()->unique();
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('birth_date')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('religion')->nullable();
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed', 'other'])->default('single');
            $table->string('blood_type')->nullable();
            $table->string('photo')->nullable();
            $table->boolean('is_contract')->default(false);
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['active', 'trainee', 'inactive', 'retired', 'terminated', 'resigned'])->default('active');
            $table->timestamps();

            $table->unique(['contact_id'], 'contact_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
