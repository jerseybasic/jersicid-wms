<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metode serah terima (dipickup kurir atau diantar/drop sendiri ke agen) dan
     * penerima WhatsApp yang bisa berupa nama grup, untuk laporan kiriman harian.
     */
    public function up(): void
    {
        Schema::table('courier_handovers', function (Blueprint $table) {
            $table->string('method', 10)->default('PICKUP')->after('courier'); // PICKUP, DROP
            $table->string('drop_point', 100)->nullable()->after('method');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Nomor 62xx atau nama grup WhatsApp
            $table->string('recipient', 100)->change();
            $table->string('topic', 30)->nullable()->after('recipient');
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['topic']);
            $table->dropColumn('topic');
        });

        Schema::table('courier_handovers', function (Blueprint $table) {
            $table->dropColumn(['method', 'drop_point']);
        });
    }
};
