<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tutup kiriman harian: catatan bahwa kiriman satu hari sudah selesai diserahkan ke kurir
        Schema::create('shipment_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date')->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('closed_at');
            $table->unsignedInteger('shipped_count')->default(0);
            $table->unsignedInteger('left_count')->default(0);
            $table->json('couriers')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_closings');
    }
};
