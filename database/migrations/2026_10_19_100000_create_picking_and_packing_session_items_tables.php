<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel item untuk sesi picking & packing.
 * Model PickingSessionItem & PackingSessionItem sudah dipakai controller,
 * tapi tabelnya belum pernah dibuat, sehingga membuat sesi picking/packing selalu gagal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('picking_session_items')) {
            Schema::create('picking_session_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('picking_session_id')->constrained('picking_sessions')->cascadeOnDelete();
                $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->string('sku');
                $table->string('bin_code')->nullable();
                $table->integer('qty_ordered');
                $table->integer('qty_picked')->default(0);
                $table->enum('status', ['PENDING', 'PARTIAL', 'COMPLETED'])->default('PENDING');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('sku');
            });
        }

        if (!Schema::hasTable('packing_session_items')) {
            Schema::create('packing_session_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('packing_session_id')->constrained('packing_sessions')->cascadeOnDelete();
                $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->string('sku');
                $table->integer('qty_ordered');
                $table->integer('qty_packed')->default(0);
                $table->enum('status', ['PENDING', 'PARTIAL', 'COMPLETED'])->default('PENDING');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('sku');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_session_items');
        Schema::dropIfExists('picking_session_items');
    }
};
