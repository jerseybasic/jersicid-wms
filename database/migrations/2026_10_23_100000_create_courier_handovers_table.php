<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serah terima paket ke kurir: paket di-scan satu per satu, lalu semuanya ditandai dikirim
        Schema::create('courier_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('handover_number')->unique();
            $table->string('courier', 50);
            $table->string('driver_name', 100)->nullable();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'courier']);
        });

        Schema::create('courier_handover_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('courier_handover_id')->constrained('courier_handovers')->cascadeOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_handover_items');
        Schema::dropIfExists('courier_handovers');
    }
};
