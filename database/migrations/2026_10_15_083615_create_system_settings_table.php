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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'low_stock_threshold'
            $table->text('value'); // JSON atau string
            $table->string('group')->default('GENERAL');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        DB::table('system_settings')->insert([
            ['key' => 'low_stock_threshold', 'value' => '10', 'group' => 'INVENTORY', 'description' => 'Alert jika stok <= nilai ini', 'created_at' => now()],
            ['key' => 'po_auto_notify', 'value' => '1', 'group' => 'NOTIFICATION', 'description' => '1 = Aktifkan notifikasi PO', 'created_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
