<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nama jasa kirim dari marketplace → ekspedisi yang menjemput paket (mis. "Shopee Xpress" → SPX)
        Schema::create('courier_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('courier', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_aliases');
    }
};
