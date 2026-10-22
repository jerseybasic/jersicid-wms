<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SKU marketplace (Shopee, Tokopedia, BigSeller) yang berbeda dari kode Master SKU
        Schema::create('sku_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 100)->unique();
            $table->string('sku')->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_aliases');
    }
};
