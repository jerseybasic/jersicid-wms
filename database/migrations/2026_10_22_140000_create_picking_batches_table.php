<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Picking gabungan: beberapa order diambil sekaligus, tiap order punya nomor keranjang
        Schema::create('picking_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_number')->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('picking_sessions', function (Blueprint $table) {
            $table->foreignUuid('batch_id')->nullable()->after('order_id')->constrained('picking_batches')->nullOnDelete();
            $table->unsignedSmallInteger('basket_no')->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('picking_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('basket_no');
        });
        Schema::dropIfExists('picking_batches');
    }
};
