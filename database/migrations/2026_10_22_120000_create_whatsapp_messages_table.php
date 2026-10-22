<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Antrean pesan WhatsApp. Makro Playwright mengambil pesan PENDING lewat API, mengirimnya, lalu melapor terkirim/gagal.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 20);
            $table->text('body');
            $table->string('status', 10)->default('PENDING'); // PENDING, SENT, FAILED
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
