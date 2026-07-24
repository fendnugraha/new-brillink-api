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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('password')->constrained();
            $table->foreignId('warehouse_id')->nullable()->after('contact_id')->constrained();
            $table->string('role')->default('Cashier')->after('warehouse_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('role');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('fcm_token')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn(['contact_id', 'warehouse_id', 'role', 'latitude', 'longitude', 'fcm_token']);
        });
    }
};
