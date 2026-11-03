<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan isi per baris rak: satu baris berisi satu artikel dan satu size.
 * Dipakai untuk menyaring SKU saat mengisi rak di Denah Gudang dan
 * menyarankan bin tujuan saat Inbound. Rak tanpa aturan tetap seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rack_row_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('warehouse_rack_id');
            $table->unsignedSmallInteger('row_index');
            $table->string('article', 100)->nullable();
            $table->string('size', 30)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_rack_id', 'row_index']);
            $table->index(['article', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_row_rules');
    }
};
