<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKU yang muncul saat import pesanan / pemetaan tetapi belum ada di Master SKU.
 * Dianggap selesai otomatis begitu kodenya dibuat di Master SKU atau dipetakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unregistered_skus', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('product_name')->nullable();
            $table->string('source', 30);
            $table->string('source_ref')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->foreignUuid('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unregistered_skus');
    }
};
