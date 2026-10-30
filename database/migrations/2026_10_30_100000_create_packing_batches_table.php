<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Packing gabungan: beberapa keranjang dikemas di satu layar, tiap order tetap punya sesi packing sendiri
        Schema::create('packing_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_number')->unique();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('IN_PROGRESS');
            // Keranjang yang sedang dikemas: scan barang masuk ke paket ini
            $table->uuid('active_session_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('packing_sessions', function (Blueprint $table) {
            $table->foreignUuid('packing_batch_id')->nullable()->after('picking_session_id')->constrained('packing_batches')->nullOnDelete();
            $table->unsignedSmallInteger('basket_no')->nullable()->after('packing_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('packing_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packing_batch_id');
            $table->dropColumn('basket_no');
        });
        Schema::dropIfExists('packing_batches');
    }
};
